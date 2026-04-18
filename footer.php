<?php /* SHVQ V2 — 공통 푸터 (모든 페이지 공통 UI) */ ?>

<!-- ══════════════════════════════════════
     공통 모달 오버레이 (SHV.modal)
     modal.js의 build()가 이 DOM을 재사용
     ══════════════════════════════════════ -->
<div class="modal-overlay" id="shvModalOverlay">
    <div class="modal-box glass-panel modal-md" id="shvModalBox">
        <div class="modal-header" id="shvModalHeader">
            <span id="shvModalTitle"></span>
            <button class="modal-close" aria-label="닫기" id="shvModalClose">&times;</button>
        </div>
        <div class="modal-body" id="shvModalBody"></div>
    </div>
</div>

<!-- ══════════════════════════════════════
     서브 모달 오버레이 (SHV.subModal)
     modal-box 위에 추가 모달 (배경 투명)
     ══════════════════════════════════════ -->
<div id="shvSubModalOverlay"
     class="fixed inset-0 z-submodal flex items-center justify-center p-4 pointer-events-none"
     style="display:none;">
    <div id="shvSubModalBox" class="modal-box pointer-events-auto">
        <div id="shvSubModalHeader" class="modal-header">
            <span id="shvSubModalTitle"></span>
            <button class="modal-close" aria-label="닫기" id="shvSubModalClose">&times;</button>
        </div>
        <div id="shvSubModalBody" class="modal-body"></div>
    </div>
</div>
