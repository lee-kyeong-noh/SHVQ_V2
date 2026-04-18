/* ========================================
   SHVQ V2 — Mail Service Worker
   설계서 v3.2 §3.5 기준
   - FCM Push 수신 → OS 알림 표시
   - 알림 클릭 → 메일 페이지 열기
   - tag: 같은 계정 알림 교체
   ======================================== */
'use strict';

/* ── 배포 경로: index.php에서 주입된 meta[name=base-url] 참조 ── */
/* SW 컨텍스트에서는 self.location.pathname으로 base 경로 추정 */
var _basePath = (function () {
    /* /SHVQ_V2/js/core/sw-mail.js → /SHVQ_V2 */
    var path = self.location.pathname || '';
    var idx  = path.lastIndexOf('/js/');
    return idx > 0 ? path.slice(0, idx) : '';
}());

/* ── Push 이벤트 수신 ── */
self.addEventListener('push', function (event) {
    if (!event.data) return;

    var data;
    try {
        data = event.data.json();
    } catch (e) {
        return;
    }

    var title = data.title || '새 메일';
    var options = {
        body:  data.body || '',
        icon:  _basePath + '/css/v2/img/mail_icon.png',
        badge: _basePath + '/css/v2/img/mail_badge.png',
        tag:   'mail-' + (data.account_idx || 'default'),
        renotify: true,
        data: {
            url:         data.click_url || (_basePath + '/?r=mail_inbox'),
            account_idx: data.account_idx,
            type:        data.type || 'newMail'
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

/* ── 알림 클릭 ── */
self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var url = (event.notification.data && event.notification.data.url)
        || (_basePath + '/?r=mail_inbox');

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
            // 이미 열린 메일 탭이 있으면 포커스
            for (var i = 0; i < windowClients.length; i++) {
                var client = windowClients[i];
                if (client.url.indexOf('system=mail') !== -1 && 'focus' in client) {
                    return client.focus();
                }
            }
            // 없으면 새 탭 열기
            return clients.openWindow(url);
        })
    );
});

/* ── SW 활성화 즉시 제어 ── */
self.addEventListener('activate', function (event) {
    event.waitUntil(clients.claim());
});
