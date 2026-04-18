/* ========================================
   SHVQ V2 — Mail Push Registration Module
   설계서 v3.2 §3.5 기준
   - 웹: Service Worker + Push API 등록
   - Capacitor: 네이티브 플러그인 연동
   - 서버 API: fcm_register / fcm_unregister
   ======================================== */
'use strict';

window.SHV = window.SHV || {};
SHV.mail  = SHV.mail || {};

SHV.mail.push = (function () {

    var SW_PATH  = (SHV.basePath || '/SHVQ_V2/') + 'js/core/sw-mail.js';
    var _registered = false;

    /* ══════════════════════════════════════
       1. 초기화 (플랫폼 자동 감지)
       ══════════════════════════════════════ */

    function init() {
        if (_registered) return Promise.resolve();

        // Capacitor 네이티브 앱인지 체크
        if (_isCapacitor()) {
            return _initCapacitor();
        }

        // 웹 브라우저
        return _initWeb();
    }

    function _isCapacitor() {
        return typeof window.Capacitor !== 'undefined'
            && window.Capacitor.isNativePlatform
            && window.Capacitor.isNativePlatform();
    }

    /* ══════════════════════════════════════
       2. 웹 브라우저 등록
       ══════════════════════════════════════ */

    function _initWeb() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('[mail.push] Push API 미지원 브라우저');
            return Promise.resolve();
        }

        return navigator.serviceWorker.register(SW_PATH).then(function (reg) {
            return reg.pushManager.getSubscription().then(function (existing) {
                if (existing) {
                    return _sendTokenToServer(existing.endpoint, 'web');
                }

                // VAPID 키가 설정되어 있어야 구독 가능
                var vapidKey = _getVapidKey();
                if (!vapidKey) {
                    console.warn('[mail.push] VAPID 키 미설정');
                    return;
                }

                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: _urlBase64ToUint8Array(vapidKey)
                }).then(function (sub) {
                    return _sendTokenToServer(sub.endpoint, 'web');
                });
            });
        }).catch(function (err) {
            console.warn('[mail.push] SW 등록 실패:', err);
        });
    }

    /* ══════════════════════════════════════
       3. Capacitor 네이티브 등록
       ══════════════════════════════════════ */

    function _initCapacitor() {
        // @capacitor/push-notifications 플러그인 사용
        var PushNotifications = window.Capacitor.Plugins.PushNotifications;
        if (!PushNotifications) {
            console.warn('[mail.push] Capacitor PushNotifications 플러그인 없음');
            return Promise.resolve();
        }

        /* 리스너를 register() 전에 등록 (이벤트 누락 방지) */
        PushNotifications.addListener('registration', function (token) {
            var deviceType = _getCapacitorPlatform();
            _sendTokenToServer(token.value, deviceType);
        });

        PushNotifications.addListener('registrationError', function (err) {
            console.warn('[mail.push] Capacitor 등록 실패:', err);
        });

        return PushNotifications.requestPermissions().then(function (result) {
            if (result.receive !== 'granted') {
                console.warn('[mail.push] 알림 권한 거부');
                return;
            }
            return PushNotifications.register();
        }).catch(function (err) {
            console.warn('[mail.push] Capacitor 초기화 실패:', err);
        });
    }

    function _getCapacitorPlatform() {
        if (window.Capacitor && window.Capacitor.getPlatform) {
            var p = window.Capacitor.getPlatform();
            if (p === 'ios') return 'ios';
            if (p === 'android') return 'android';
        }
        return 'hybrid';
    }

    /* ══════════════════════════════════════
       4. 서버에 토큰 등록/해제
       ══════════════════════════════════════ */

    function _sendTokenToServer(token, deviceType) {
        if (!token) return Promise.resolve();

        _registered = true;
        return SHV.mail.apiPost('fcm_register', {
            token:       token,
            device_type: deviceType || 'web'
        }).catch(function (err) {
            console.warn('[mail.push] 토큰 등록 실패:', err);
            _registered = false;
        });
    }

    function unregister() {
        if (!('serviceWorker' in navigator)) return Promise.resolve();

        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            if (!sub) return;

            var token = sub.endpoint;
            return Promise.all([
                sub.unsubscribe(),
                SHV.mail.apiPost('fcm_unregister', { token: token })
            ]);
        }).then(function () {
            _registered = false;
        }).catch(function (err) {
            console.warn('[mail.push] 해제 실패:', err);
        });
    }

    /* ══════════════════════════════════════
       5. 알림 권한 요청
       ══════════════════════════════════════ */

    function requestPermission() {
        if (!('Notification' in window)) return Promise.resolve('denied');
        if (Notification.permission === 'granted') return Promise.resolve('granted');
        if (Notification.permission === 'denied') return Promise.resolve('denied');

        return Notification.requestPermission();
    }

    function getPermissionStatus() {
        if (!('Notification' in window)) return 'unsupported';
        return Notification.permission;
    }

    /* ══════════════════════════════════════
       6. 유틸
       ══════════════════════════════════════ */

    function _getVapidKey() {
        return window.SHV_VAPID_KEY
            || 'BBrJEBtiaO2zT9ulg16-EnLZqO9Mm5Kz-NI6O1MPp5dNSHPh-z9CkxuntcJkr6MPGUqZwo86ePL2o2SOZh9lvO8';
    }

    function _urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw     = atob(base64);
        var arr     = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            arr[i] = raw.charCodeAt(i);
        }
        return arr;
    }

    /* ══════════════════════════════════════
       Public API
       ══════════════════════════════════════ */

    return {
        init:                init,
        unregister:          unregister,
        requestPermission:   requestPermission,
        getPermissionStatus: getPermissionStatus
    };

})();
