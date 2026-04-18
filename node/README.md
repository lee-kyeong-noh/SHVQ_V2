# SHVQ V2 — Mail Worker

Node.js IMAP IDLE + WebSocket 실시간 메일 알림 서버

## 설치

```bash
cd D:\SHV_ERP\SHVQ_V2\node
npm install
```

## 실행 (개발)

```bash
node worker.js --verbose
```

## 실행 (PM2 운영)

```bash
pm2 start ecosystem.config.js
pm2 save
pm2 startup  # 서버 재시작 시 자동 시작
```

## 환경변수 설정 (`ecosystem.config.js`)

| 변수 | 설명 |
|------|------|
| `SHV_MAIL_DB_SERVER` | MSSQL 서버 주소 |
| `SHV_MAIL_DB_USER` | DB 사용자 |
| `SHV_MAIL_DB_PASSWORD` | DB 비밀번호 |
| `SHV_MAIL_DB_NAME` | `CSM_C004732_V2` 고정 |
| `MAIL_CREDENTIAL_KEY` | PHP `.env`의 동일 키 값 (암호화 복호화) |
| `BROADCAST_SECRET` | `/broadcast` API 인증 비밀값 |
| `REDIS_HOST` | Redis 서버 (기본 127.0.0.1) |

## SSL (wss://)

`node/` 폴더에 `cert.pem`, `key.pem` 파일 배치 시 자동으로 WSS 모드로 실행됩니다.

## WebSocket 연결

```javascript
// 브라우저에서 ws_token_issue API로 토큰 발급 후 연결
const ws = new WebSocket('wss://shvq.kr:2347/?token=TOKEN');
ws.onmessage = (e) => {
    const data = JSON.parse(e.data);
    if (data.type === 'newMail') {
        // 새 메일 알림 처리
    }
};
```

## SSE 연결 (폴백)

```javascript
const es = new EventSource('https://shvq.kr:2347/sse?token=TOKEN');
es.onmessage = (e) => { const data = JSON.parse(e.data); };
```
