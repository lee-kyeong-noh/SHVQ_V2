# SHVQ V2 메일 + 실시간 알림 아키텍처 설계서 (최종)

**문서 버전**: 3.2 (최종) | **작성일**: 2026-04-14
**대상 규모**: 10,000+ 업장회원 (SaaS 멀티테넌트)
**동시접속**: 1,000~3,000명 | **실시간 목표**: 3~5초
**서버 목표**: 16GB RAM 단일 서버로 운영
**역할**: ChatGPT(백엔드/Node/DB) | Claude(프론트엔드/JS/CSS)

---

## Context

메일이 ERP 핵심 기능. 만명 이상 업장회원이 사용하는 SaaS 구조.
**핵심 원칙: "서버는 라우터, 데이터는 클라이언트+FCM으로 밀어라"**

- 서버: 헤더/메타만 저장 + IMAP 중계 + 알림 라우팅
- 클라이언트: 본문 저장 + 검색 전담 (IndexedDB)
- 알림: FCM 기본, SSE는 메일탭 활성 시 보조
- 유저 증가 ≠ 서버 비용 증가

---

## 1. 전체 아키텍처

```
IMAP 서버 (외부)
  ↓
Node Worker (2 인스턴스, 최소 처리)
  ├─ IMAP IDLE: 활성 유저만 (300~800)
  ├─ IMAP FETCH priority queue (최신 uid 우선, 동시 5~10)
  ├─ FETCH 실패 retry (3회 → cron fallback)
  ├─ 헤더 + 200자 preview → DB bulk INSERT (transaction 묶음)
  ├─ IMAP watchdog (10분 무이벤트 → reconnect)
  └─ 알림 분기:
      ├─ 메일탭 활성 → SSE (300~500, rate limit 10/sec/user)
      └─ 그 외 → FCM push (sliding debounce 최대 1분)
  ↓
DB (헤더만, 본문 저장 금지)
  ↓
┌──────────────┬────────────────┬──────────────────┐
│              │                │                  │
v              v                v                  v
FCM          SSE              IndexedDB         Cron
(기본 알림)  (메일탭, 1탭)   (본문/검색 전담)   (오프라인 sync)
```

---

## 2. 서버 아키텍처 (ChatGPT 담당)

### 2.1 Node.js 워커

| 설정 | 현재 | 변경 |
|------|------|------|
| PM2 instances | 1 (fork) | **2 (cluster)** |
| max_memory_restart | 512MB | **4GB** |
| DB pool.max | 50 | **50/인스턴스 (총 100)** |
| node --max-old-space-size | 기본 | **3500** |

스케일 기준:
- IMAP 연결 / worker ≤ **400**
- SSE 연결 / worker ≤ **300**
- 초과 시 → 3 인스턴스로 확장

계정 분배: `accountIdx % WORKER_COUNT === WORKER_ID`

### 2.2 IMAP IDLE 관리

**대상 기준:**
```
IDLE 시작 조건:
  (최근 30분 메일 페이지 활동) OR (0 < unread ≤ 200)

IDLE 해제 조건:
  (30분 비활동) AND (unread === 0) → 30초 유예 후 해제

강제 cron fallback:
  unread > 200 → IDLE 시작 안 함, cron으로 처리
```

**IMAP FETCH priority queue + aging (필수):**
```
EXISTS 이벤트 → 즉시 FETCH 하지 않음
  → enqueueFetch(accountIdx, uid)
  → priority = uid + (waitingTime * agingFactor)
    - 최신 메일(높은 uid) 우선 → 3~5초 체감 속도 직결
    - aging: 대기 시간 누적 → 오래된 항목도 결국 처리 (starvation 방지)
  → worker pool: 동시 최대 5~10건 처리
  → 대용량 메일 블로킹 방지, latency 일정 유지
```

**FETCH 실패 retry (필수):**
```
FETCH 실패 (IMAP 서버 불안정, 타임아웃 등)
  → retry queue 재삽입 (최대 3회, exponential backoff)
  → 3회 실패 → 해당 메일은 cron fallback으로 넘김
  → 나머지 큐는 정상 처리 계속 (1건 실패가 전체 블로킹 X)
```

**reconnect backoff:**
```
retry: 1초 → 3초 → 10초 → 30초 → 60초 → 300초 (최대)
실패 5회 이상 → cron fallback 전환
```

**IMAP watchdog (필수):**
```
10분간 IDLE 이벤트 없음 → 자동 reconnect
목적: IMAP silent death 방지 (실제로 흔한 문제)
```

생명주기:
```
[메일 페이지 활성 + SSE 연결]
  → Redis SETEX mail:online:<userPk> 60 "1"
  → 클라이언트 heartbeat: 매 30초 → SETEX 갱신 (TTL 리셋)
  → IDLE 시작 조건 충족 → startImapIdle(account)

[SSE 종료 또는 30분 비활동]
  → 0 < unread ≤ 200 → IDLE 유지
  → unread === 0 또는 unread > 200 → 30초 유예 → IDLE 해제
  → heartbeat 중단 → 60초 후 Redis 키 자동 만료 (유령 상태 불가)
```

동시 IDLE 상한:
- 인스턴스당 soft limit: **400**
- **global hard cap: 800** (모든 인스턴스 합산, 예외 상황 폭주 방지)
- hard cap 도달 시 → 새 IDLE 거부, cron fallback

### 2.3 DB 스키마

#### 변경 없는 기존 테이블
- Tb_IntProviderAccount, Tb_IntCredential (그대로)
- Tb_Mail_FolderSyncState, Tb_Mail_WsToken, Tb_Mail_FilterRule (그대로)

#### Tb_Mail_MessageCache 컬럼 수정
```sql
-- body_preview: 1000 → 200자로 축소, HTML 제거 후 저장
ALTER TABLE Tb_Mail_MessageCache
  ALTER COLUMN body_preview NVARCHAR(200);

-- body_hash: IndexedDB 캐시 무효화 판별용
ALTER TABLE Tb_Mail_MessageCache
  ADD body_hash VARCHAR(64) NULL;

-- fcm_notified: FCM 중복 방지
ALTER TABLE Tb_Mail_MessageCache
  ADD fcm_notified BIT NOT NULL DEFAULT 0;
```

**body_preview 저장 규칙 (절대 raw HTML 저장 금지):**
```javascript
function makePreview(html) {
  return html
    .replace(/<[^>]+>/g, '')
    .replace(/&[^;]+;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 200);
}
```

**DB write batching + transaction + deadlock 방지 (필수):**
```
INSERT 1건씩 → bulk INSERT 10~50 rows + transaction 묶음

// accountIdx 기준 batch grouping (deadlock 방지)
// 같은 account 데이터는 반드시 같은 batch로 묶기
const grouped = groupBy(rows, 'account_idx');
for (const [accIdx, accRows] of grouped) {
  await sql.transaction(async (tx) => {
    await tx.bulkInsert(accRows);  // WITH (ROWLOCK) 힌트 적용
  });
}

효과: write latency 안정화 + lock contention 감소 + deadlock 차단
다중 worker 동시 INSERT 시 accountIdx 분리로 충돌 방지
```

#### Tb_Mail_FcmToken (신규)
```sql
CREATE TABLE Tb_Mail_FcmToken (
    id          INT IDENTITY(1,1) PRIMARY KEY,
    user_pk     INT NOT NULL,
    tenant_id   INT NOT NULL DEFAULT 0,
    token       NVARCHAR(500) NOT NULL,
    device_type VARCHAR(20) NOT NULL DEFAULT 'web',
    user_agent  NVARCHAR(300) NULL,
    created_at  DATETIME NOT NULL DEFAULT GETDATE(),
    updated_at  DATETIME NOT NULL DEFAULT GETDATE()
);
CREATE UNIQUE INDEX UX_FcmToken_Token ON Tb_Mail_FcmToken(token);
CREATE INDEX IX_FcmToken_UserPk ON Tb_Mail_FcmToken(user_pk, tenant_id);
```

#### ~~Tb_Mail_SearchIndex~~ → 제거 (서버 검색 안 함)
서버는 MessageCache의 subject/from_address LIKE만. 본문 검색은 IndexedDB.

### 2.4 Redis (최소 사용)

| 키 | 타입 | TTL | 용도 |
|-----|------|-----|------|
| `mail:user:<userPk>` | pub/sub | - | 실시간 알림 (현재 동일) |
| `mail:online:<userPk>` | STRING | **60초** | 온라인 상태 (heartbeat 갱신) |

**Redis 유령 online 방지 (필수):**
```
// 기존 SET 방식 → SETEX + heartbeat으로 변경
// 브라우저 크래시 시 SREM 안 되는 문제 완전 해결

// SSE 연결 시
SETEX mail:online:<userPk> 60 "1"

// 클라이언트 heartbeat (매 30초)
SETEX mail:online:<userPk> 60 "1"  // TTL 갱신

// 온라인 체크
EXISTS mail:online:<userPk>

// 브라우저 크래시 → heartbeat 중단 → 60초 후 자동 만료
// SREM 누락 불가능 (TTL이 보장)
```

그 외 키 전부 제거 (unread 캐시, idle_owner, last_sync 등 불필요)

### 2.5 FCM 알림 구조

**알림 분기:**
```
새 메일 감지
  ├─ Redis SISMEMBER mail:online_users <userPk>
  │   ├─ YES → SSE 전송 (FCM 안 보냄)
  │   └─ NO  → FCM 전송 (SSE 안 보냄)
  └─ 동시 전송 안 함 → 중복 방지
```

**FCM sliding debounce (필수):**
```
debounce key = userPk + accountIdx (계정별 분리)
5초 debounce + 최대 1분 sliding window
1통 → 즉시 "홍길동: 견적서 송부..."
5초 내 추가 → 묶음 대기
1분 경과 또는 추가 없음 → "[계정A] 새 메일 3건" / "[계정B] 새 메일 2건" 분리 전송
```

**FCM 실패 처리 (필수):**
```javascript
if (error.code === 'messaging/registration-token-not-registered'
    || error.code === 'messaging/invalid-registration-token') {
  DELETE FROM Tb_Mail_FcmToken WHERE token = :token
}
```

**FCM retry + cron fallback 보장:**
```
FCM 전송 실패
  → 1회 retry (1~2초 후, 일시적 네트워크 오류 대응)
  → retry 실패 → fcm_notified = 0 유지
  → 다음 cron cycle에서 해당 계정 우선 동기화
  → 알림 누락 없음
```

### 2.6 Cron 최적화

| 설정 | 현재 | 변경 |
|------|------|------|
| 배치 크기 | 50 | **200** |
| 대상 | 전체 활성 | **24시간 내 로그인 + 오프라인** |
| 효과 | 10,000/16시간 | **~3,000(70% 제외)/~1.5시간** |

```sql
WHERE a.provider = 'mail' AND a.status = 'ACTIVE'
  AND m.last_login_at > DATEADD(day, -1, GETDATE())
  AND a.user_pk NOT IN (온라인 유저 SET)
ORDER BY fs.last_synced_at ASC
```

- 로그인 시점 즉시 sync 트리거 (last_synced_at 5분 이상 경과 시)

---

## 3. 클라이언트 아키텍처 (Claude 담당)

### 3.1 IndexedDB 스키마

DB명: `shvq_mail_v2` | 버전: 1

| Store | keyPath | 주요 Index | 용도 |
|-------|---------|-----------|------|
| `mail_body` | `cacheKey` (`{accIdx}_{folder}_{uid}`) | by_account, by_accessedAt | 본문 캐시 |
| `mail_headers` | `cacheKey` | by_account_folder_date | 오프라인 목록 헤더 |
| `search_tokens` | autoIncrement | by_account_token | body 전문 검색 |
| `sync_meta` | `key` | - | 동기화 상태 |

**mail_body 레코드:**
```javascript
{
  cacheKey: '42_INBOX_15234',
  accountIdx: 42, folder: 'INBOX', uid: 15234,
  bodyHtml: '...', bodyText: '...',
  bodyHash: 'sha256...', 
  cachedAt: timestamp, accessedAt: timestamp,
  sizeBytes: 45000,
  attachments: [{ name, size, contentType }]
}
```

### 3.2 Body 캐시 전략

```
메일 클릭
  → IndexedDB mail_body.get(cacheKey)
  → HIT + bodyHash 일치 → 즉시 렌더링 (0ms), accessedAt 갱신
  → HIT + bodyHash 불일치 → 서버 재요청 → IndexedDB 갱신
  → MISS → PHP API mail_detail → IMAP body fetch
         → 화면 표시 + IndexedDB 저장
         → 토큰화 → search_tokens 저장
         → eviction 체크
```

**Eviction:**
- 상한: **500MB** (모든 계정 합산)
- 목표: 400MB까지 삭제
- 기준: `accessedAt` 오래된 순 (LRU)
- 트리거: **3중 체크**
  1. 매 body 저장 직후
  2. 앱 시작 시 1회
  3. **setInterval 5분 주기** (백그라운드 누적 방지, 모바일 필수)

**IndexedDB corruption 복구 (필수):**
```javascript
// iOS/일부 Android에서 DB 손상 실제로 발생
async function openMailDB() {
  try {
    return await idb.openDB('shvq_mail_v2', 1, { upgrade });
  } catch (e) {
    console.error('IndexedDB corrupted, recreating...', e);
    await idb.deleteDB('shvq_mail_v2');
    return await idb.openDB('shvq_mail_v2', 1, { upgrade });
  }
}
// 캐시 데이터이므로 삭제 후 재생성해도 데이터 손실 없음
// 서버 헤더 + IMAP 본문에서 다시 채워짐
```

**영속성:**
```javascript
if (navigator.storage && navigator.storage.persist) {
  navigator.storage.persist();
}
```

### 3.3 검색 구조

```
검색어: "견적서 가격"
  │
  ├─ 1순위: IndexedDB 검색 (즉시)
  │   search_tokens by_account_token 인덱스
  │   AND 조건: 모든 토큰 매칭된 cacheKey만
  │   결과 → 화면 즉시 표시
  │
  └─ 2순위: 서버 fallback (병렬)
      SQL: subject LIKE + from_address LIKE
      결과 → IndexedDB 결과와 병합 (uid 중복 제거)

  UI: "본문 검색은 열어본 메일 기준입니다"
```

**토큰화:**
```javascript
function tokenize(text) {
  const cleaned = text
    .replace(/<[^>]+>/g, '').replace(/&[^;]+;/g, ' ').toLowerCase();
  const ko = cleaned.match(/[가-힣]{2,}/g) || [];
  const en = cleaned.match(/[a-z0-9]{2,}/g) || [];
  return [...new Set([...ko, ...en])];
}
```

### 3.4 SSE 실시간 연결

**핵심: BroadcastChannel API로 탭 1개만 SSE 유지**

```javascript
const bc = new BroadcastChannel('shvq_mail_sse');

// 탭 활성화 시
bc.postMessage({ type: 'tab_active', tabId: MY_TAB_ID });

// 다른 탭 활성 수신 → 내 SSE 해제
bc.onmessage = (e) => {
  if (e.data.type === 'tab_active' && e.data.tabId !== MY_TAB_ID) {
    disconnectSSE();
  }
  if (e.data.type === 'mail_event') {
    handleMailEvent(e.data.payload); // 다른 탭 이벤트도 반영
  }
};
```

**SSE rate limit + backpressure (필수):**
```
서버 측: 유저당 최대 10 events/sec
초과 시 drop (메일 폭주 상황 보호)

// backpressure: 느린 클라이언트 보호
if (client.writableLength > 64 * 1024) {  // 64KB 버퍼 초과
  client.destroy();  // 강제 disconnect → 클라이언트 자동 재연결
}
```

**BroadcastChannel 이벤트 루프 방지 (필수):**
```javascript
// SSE에서 온 이벤트만 BC로 전파, BC에서 온 건 재전파 금지
function handleEvent(payload, source) {
  if (source === 'sse') {
    bc.postMessage({ ...payload, source: 'bc' });  // 다른 탭에 전달
    updateUI(payload);
  }
  if (source === 'bc') {
    updateUI(payload);  // UI만 갱신, 재broadcast 금지
  }
}
// 안 하면 이벤트 무한 루프 → CPU 100% (실제 빈번한 버그)
```

**SSE reconnect jitter (필수):**
```javascript
// 5분 만료 후 동시 재연결 방지 (thundering herd)
const jitter = Math.random() * 5000; // 0~5초 랜덤
setTimeout(() => reconnectSSE(), jitter);
```

**Visibility API:**
```javascript
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') {
    refreshUnreadCount();
  }
});
```

### 3.5 Service Worker + FCM Push

**js/core/sw-mail.js:**
```javascript
self.addEventListener('push', (event) => {
  const data = event.data.json();
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: '/css/v2/img/mail_icon.png',
      tag: 'mail-' + data.account_idx,
      data: { url: data.click_url }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data.url));
});
```

**js/mail/push.js:**
```javascript
// 웹 브라우저
async function registerFcmToken() {
  const reg = await navigator.serviceWorker.register('/js/core/sw-mail.js');
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true, applicationServerKey: VAPID_KEY
  });
  await SHV.api.post('Mail.php', {
    todo: 'fcm_register', token: sub.endpoint, device_type: 'web'
  });
}

// Capacitor 하이브리드 앱
import { PushNotifications } from '@capacitor/push-notifications';
PushNotifications.addListener('registration', (token) => {
  SHV.api.post('Mail.php', {
    todo: 'fcm_register', token: token.value, device_type: 'android'
  });
});
```

### 3.6 하이브리드 앱 (Capacitor)

| 이슈 | 해결 |
|------|------|
| IndexedDB 용량 | Android 무제한, iOS 1GB → 500MB 상한 안전 |
| 백그라운드 SSE | SSE 해제 → FCM 전환 (자동) |
| FCM 토큰 | Capacitor `@capacitor/push-notifications` |
| 영속성 | `navigator.storage.persist()` |
| BroadcastChannel | 앱은 단일 WebView → 이슈 없음 |

---

## 4. 데이터 흐름

### 4.1 새 메일 — 메일탭 활성 유저 (3~5초)

```
IMAP 서버 → EXISTS
  → Node: enqueueFetch(accIdx, uid, priority=uid DESC) [즉시 FETCH X]
  → priority queue → worker pool (동시 5~10, 최신 메일 우선)
  → IMAP FETCH envelope + bodyStructure
     ├─ 성공 → 다음 단계
     └─ 실패 → retry queue (최대 3회, backoff) → 3회 실패 → cron fallback
  → makePreview(body) [HTML 제거 + 200자]
  → DB bulk INSERT + transaction (10~50 rows 묶음)
  → Redis EXISTS mail:online:<userPk> → online!
  → SSE 전송 (rate limit 10/sec/user):
      { type:'newMail', unread:N+1, account_idx }
  → 정확 unread 재조회:
      { type:'mailSynced', unread:정확값 }

브라우저:
  → SSE 수신 (source='sse') → UI 갱신 + 토스트
  → BroadcastChannel (source='bc') → 다른 탭 UI만 갱신 (재전파 금지)
```

### 4.2 새 메일 — 오프라인/다른 페이지 유저

```
Node → Redis EXISTS mail:online:<userPk> → offline!
  → FCM sliding debounce (5초 + 최대 1분 window)
  → 1통: "홍길동: 견적서 송부..."
  → N통 (계정별 분리): "[계정A] 새 메일 3건" / "[계정B] 새 메일 2건"
  → firebase-admin → FCM → Service Worker → OS 알림
  → 클릭 → 메일 페이지 열기

FCM 실패 시:
  → fcm_notified = 0 유지
  → 다음 cron cycle에서 해당 계정 우선 sync
  → 알림 누락 방지
```

### 4.3 메일 본문 열기

```
클릭 → IndexedDB mail_body.get(cacheKey)
  → HIT → 즉시 렌더링 (0ms)
  → MISS → PHP Mail.php?todo=mail_detail
         → IMAP FETCH body
         → 응답: { body_html, body_text, body_hash, attachments }
         → 화면 렌더링
         → IndexedDB 저장 + 토큰화 + eviction 체크
```

### 4.4 메일 검색

```
"견적서 가격"
  ├─ IndexedDB: search_tokens → 즉시 결과
  └─ 서버: subject/from LIKE → 병합 (중복 제거)

UI: "본문 검색은 열어본 메일 기준입니다"
```

### 4.5 로그인 후 동기화

```
로그인 → PHP 세션
  → 메일 목록 API (last_synced_at 5분 경과 → 즉시 sync)
  → IndexedDB mail_headers merge
  → 메일 페이지 진입 → SSE 연결 → IDLE 시작
```

---

## 5. 스케일링 수치

### 서버 메모리 (16GB)

| 항목 | 수치 |
|------|------|
| IMAP IDLE (3.5MB × 800) | 2.8GB |
| SSE (12KB × 500) | 6MB |
| Node.js 2 인스턴스 힙 | 2GB |
| Redis (pub/sub + SET) | 0.5GB |
| MSSQL | 6GB |
| OS + 여유 | 4.7GB |
| **합계** | **~16GB** ✅ |

### 클라이언트 IndexedDB (사용자당)

| 데이터 | 용량 |
|--------|------|
| mail_body (2,000건) | ~50MB |
| mail_headers (10,000건) | ~5MB |
| search_tokens | ~10MB |
| **합계** | **~65MB** (상한 500MB) |

### 동시 연결 수

| 항목 | 수치 |
|------|------|
| 총 등록 유저 | 10,000+ |
| 동시 접속 | 1,000~3,000 |
| 메일 페이지 활성 | 300~500 |
| SSE 연결 | 300~500 (1탭) |
| IMAP IDLE | 300~800 (30분 활동 OR 0<unread≤200) |

---

## 6. 필수 체크리스트

### 보안/데이터
- [ ] body_preview HTML 제거 후 200자 (XSS 방지, raw HTML 금지)
- [ ] 본문 서버 저장 금지 (개인정보/법적 리스크)
- [ ] FCM NotRegistered → token DB 삭제

### IMAP 안정성
- [ ] IMAP fetch **priority queue + aging** (최신 우선 + starvation 방지)
- [ ] IMAP FETCH 실패 retry 3회 → cron fallback
- [ ] IMAP reconnect backoff (1s→3s→10s→30s→60s→300s)
- [ ] IMAP watchdog (10분 무이벤트 → reconnect)
- [ ] IMAP global hard cap 800 (폭주 방지)
- [ ] IDLE 조건: (30분 활동) OR (0 < unread ≤ 200)
- [ ] unread > 200 → 강제 cron fallback

### 알림
- [ ] FCM sliding debounce (5초 + 최대 1분, key=userPk+accountIdx)
- [ ] FCM 1회 retry → 실패 시 cron fallback (fcm_notified=0 유지)
- [ ] SSE 단일 탭 (BroadcastChannel API)
- [ ] SSE BroadcastChannel 이벤트 루프 방지 (source 체크)
- [ ] SSE rate limit (10/sec/user) + backpressure (64KB 초과 → disconnect)
- [ ] SSE reconnect jitter (random 0~5초, thundering herd 방지)

### 클라이언트
- [ ] IndexedDB eviction 3중 체크 (저장 후 + 시작 시 + 5분 주기)
- [ ] IndexedDB corruption 복구 (catch → deleteDB → recreate)
- [ ] navigator.storage.persist() 최초 호출
- [ ] 검색: IndexedDB 우선 + 서버 subject/from fallback

### Cron
- [ ] 배치 200 + 24시간 로그인 필터 + 온라인 제외
- [ ] 로그인 시점 즉시 sync 트리거

### 서버 상태
- [ ] Redis online → SETEX + heartbeat (유령 online 방지)
- [ ] bulk INSERT transaction + accountIdx grouping (deadlock 방지)
- [ ] ROWLOCK 힌트 적용

---

## 7. 마이그레이션 계획

### Phase 1: 인프라 (ChatGPT, 1주)
1. SQL: Tb_Mail_FcmToken 생성, MessageCache 컬럼(body_hash, fcm_notified), body_preview 200자
2. ecosystem.config.js: cluster 2, 메모리 4GB
3. worker.js: IMAP fetch queue + online-only IDLE + unread 조건 + watchdog + reconnect backoff
4. worker.js: FCM 분기 + sliding debounce + invalid token 정리 + cron fallback
5. Redis: online_users SET, 불필요 키 제거
6. node/fcm.js: firebase-admin 래퍼

### Phase 2: 클라이언트 IndexedDB + 실시간 (Claude, 1주)
1. `js/mail/indexeddb.js` — 스키마 + CRUD + eviction (3중 체크)
2. `js/mail/cache.js` — body 캐시 (LRU, 500MB, persist)
3. `js/mail/realtime.js` — SSE 단일탭 + BroadcastChannel + jitter reconnect + Visibility API
4. `js/mail/search.js` — 토큰화 + IndexedDB 검색 + 서버 fallback 병합
5. `js/pages/mail.js`, `mail_pages.js` 수정 — 연동
6. `css/v2/pages/mail.css` — 토스트 알림 스타일

### Phase 3: FCM Push (공동, 1주)
- Claude: `js/core/sw-mail.js`, `js/mail/push.js`
- ChatGPT: `node/fcm.js`, Mail.php `fcm_register`/`fcm_unregister`

### Phase 4: Cron 최적화 (ChatGPT, 3일)
- mail_sync_cron.php: 200 배치 + 24시간 필터 + 온라인 제외
- 로그인 시점 즉시 sync

### Phase 5: 부하 테스트 (1주)
- 500 SSE + 400 IMAP IDLE 동시 테스트
- IndexedDB eviction 500MB 동작 확인
- FCM 지연 측정 (목표: 평균 3초 이내)
- PM2 monit 72시간 메모리 모니터링
- BroadcastChannel 멀티탭 중복 없음 확인
- IMAP watchdog 10분 reconnect 동작 확인

---

## 8. 파일 목록

### 신규 (Claude)
| 파일 | 용도 |
|------|------|
| `js/mail/indexeddb.js` | IndexedDB 스키마 + CRUD + eviction |
| `js/mail/cache.js` | body 캐시 전략 |
| `js/mail/realtime.js` | SSE 단일탭 + BroadcastChannel + jitter |
| `js/mail/search.js` | 토큰화 + IndexedDB 검색 |
| `js/mail/push.js` | FCM/SW 등록 |
| `js/core/sw-mail.js` | Service Worker (FCM 수신) |

### 신규 (ChatGPT)
| 파일 | 용도 |
|------|------|
| `node/fcm.js` | firebase-admin + sliding debounce |
| `scripts/migrations/XXXX_wave4_fcm_token.sql` | FcmToken + MessageCache 컬럼 |

### 수정 (Claude)
| 파일 | 변경 |
|------|------|
| `js/pages/mail.js` | IndexedDB/realtime 연동 |
| `js/pages/mail_pages.js` | body 캐시 + 검색 병합 |
| `css/v2/pages/mail.css` | 토스트 알림 스타일 |

### 수정 (ChatGPT)
| 파일 | 변경 |
|------|------|
| `node/worker.js` | fetch queue + online-only IDLE + watchdog + reconnect backoff + FCM 분기 + SSE rate limit + bulk INSERT |
| `node/ecosystem.config.js` | cluster 2, 메모리 4GB |
| `cron/saas/mail_sync_cron.php` | 200 배치 + 24시간 필터 + 온라인 제외 |
| `dist_process/saas/Mail.php` | fcm_register/unregister API |

---

## 부록: 운영 전략 (서비스 투입 후)

### A. IMAP 서버별 provider profile

메일 서버마다 동작 편차가 큼. 운영 중 프로파일 수집 후 적용.

```javascript
const providerConfig = {
  'imap.gmail.com':       { idleTimeout: 29*60, fetchConcurrency: 10, retryStrategy: 'normal' },
  'imap.naver.com':       { idleTimeout: 20*60, fetchConcurrency: 5,  retryStrategy: 'aggressive' },
  'outlook.office365.com':{ idleTimeout: 25*60, fetchConcurrency: 3,  retryStrategy: 'conservative' },
  'default':              { idleTimeout: 25*60, fetchConcurrency: 5,  retryStrategy: 'normal' }
};
// 계정 생성 시 host 기반 자동 매칭
```

### B. FCM 품질 모니터링

FCM은 제어 불가 영역 (iOS 저전력, Android Doze). 알림 품질 추적용 로그 수집 권장.

```
Tb_Mail_FcmLog (운영 안정화 후 추가)
  - user_pk, account_idx, sent_at, delivered_at (클라이언트 보고)
  - latency_ms, status (sent/delivered/clicked/failed)

수집 데이터 활용:
  - 평균 FCM 지연 시간 대시보드
  - provider별 도착률 비교
  - 알림 클릭률 → UX 개선 근거
```

---

## 검증 방법

1. **IndexedDB**: DevTools > Application > IndexedDB — shvq_mail_v2 저장/eviction/persist
2. **실시간 SSE**: 메일탭 열고 외부 메일 → 3~5초 토스트 확인
3. **FCM**: 메일탭 닫고 외부 메일 → OS 알림 확인
4. **단일 탭**: 메일 탭 2개 → SSE 1개만 (DevTools Network)
5. **검색**: 본문 키워드 → IndexedDB + 서버 병합 확인
6. **IMAP watchdog**: 10분 무이벤트 → reconnect 로그 확인
7. **FCM debounce**: 연속 5통 발송 → "새 메일 5건" 1개 알림
8. **스케일**: PM2 monit 72시간 메모리 추이
9. **하이브리드**: Capacitor → IndexedDB 영속 + FCM 네이티브
10. **fetch priority**: 연속 10통 수신 → 최신 uid 먼저 DB 반영 확인
11. **FETCH retry**: IMAP 서버 강제 끊김 → 3회 retry 후 cron fallback 확인
12. **BC 루프 방지**: 메일탭 3개 열기 → 이벤트 1회만 전파 확인 (CPU 안정)
13. **IDB corruption**: DevTools에서 IndexedDB 수동 삭제 → 자동 복구 확인
14. **FCM 계정 분리**: 2개 계정 동시 수신 → 계정별 별도 알림 확인
