<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/dist_library/saas/security/init.php';

$cadUrl = '';
$error = '';

try {
    $auth = new AuthService();
    $issued = $auth->issueCadToken();
    if (!is_array($issued) || !($issued['ok'] ?? false)) {
        $error = (string)($issued['error'] ?? 'CAD_TOKEN_ISSUE_FAILED');
    } else {
        $token = (string)($issued['token'] ?? '');
        if ($token === '') {
            $error = 'CAD_TOKEN_EMPTY';
        } else {
            $cadUrl = 'CAD/cad.php?token=' . rawurlencode($token) . '&from=portal';
        }
    }
} catch (Throwable $e) {
    $error = shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'CAD_TOKEN_ISSUE_EXCEPTION';
}

header('Content-Type: text/html; charset=utf-8');
?>
<div data-title="SmartCAD"></div>
<?php if ($cadUrl !== ''): ?>
<style>
.cad-iframe-wrap{position:relative;width:100%;height:calc(100vh - 82px);border:none;border-radius:12px;overflow:hidden;background:#0b1120}
.cad-iframe-wrap iframe{width:100%;height:100%;border:none}
.cad-iframe-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#0b1120;color:#8ab4d4;font-size:13px;z-index:1;transition:opacity 0.3s}
.cad-iframe-loading.loaded{opacity:0;pointer-events:none}
</style>
<div class="cad-iframe-wrap">
    <div class="cad-iframe-loading" id="cadLoading">SmartCAD 로딩 중...</div>
    <iframe id="cadIframe" src="<?php echo htmlspecialchars($cadUrl, ENT_QUOTES, 'UTF-8'); ?>" allow="clipboard-write" onload="document.getElementById('cadLoading').classList.add('loaded')"></iframe>
</div>
<script>
(function(){
    // content 패딩 제거 (CAD 전체화면)
    var content = document.getElementById('content');
    if(content){ content.style.padding = '0'; content.style.overflow = 'hidden'; }
    // 페이지 떠날 때 패딩 복원
    if(window.SHV && SHV.router){
        SHV.router.onLoad(function(info){
            if(info.route !== 'smartcad' && content){
                content.style.padding = '';
                content.style.overflow = '';
            }
        });
    }
})();
</script>
<?php else: ?>
<div style="padding:24px;text-align:center">
    <div style="color:#ff4466;font-size:14px;margin-bottom:8px">CAD 연결에 실패했습니다.</div>
    <div style="color:#5f6b7b;font-size:13px">원인: <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
</div>
<?php endif; ?>