/* SHV WebCAD - Config */

// 도면 상태값 (PHP config.php에서 주입됨)
const CAD_STATUS = window._CAD_STATUS || {
  0: {label:'작성', color:'#00aaff'},
  1: {label:'산출', color:'#ffaa00'},
  2: {label:'완료', color:'#00cc88'},
  3: {label:'반려', color:'#ff4466'},
  4: {label:'보류', color:'#888888'},
  5: {label:'승인', color:'#aa66ff'},
};

// 사용자 레벨
const CAD_LEVELS = window._CAD_LEVELS || {
  0:'게스트', 1:'뷰어', 2:'작성자', 3:'검수자', 4:'관리자', 5:'시스템관리자'
};

// 권한
const CAD_PERMISSIONS = window._CAD_PERMISSIONS || {};

// 상태별 수정 가능 최소 레벨
const CAD_STATUS_EDIT_LEVEL = window._CAD_STATUS_EDIT_LEVEL || {};

// 현재 사용자
const CAD_USER = window._CAD_USER || {id:'guest', name:'게스트', level:0};

// 상태 코드(숫자)로 조회
function getStatusByCode(code){
  const c = (typeof code==='string') ? parseInt(code) : code;
  return CAD_STATUS[c] || CAD_STATUS[0];
}

// 권한 체크: 현재 사용자가 특정 권한이 있는지
function hasPermission(perm){
  const lv = String(CAD_USER.level);
  const p = CAD_PERMISSIONS[lv] || CAD_PERMISSIONS[parseInt(lv)];
  if(!p) return false;
  return !!p[perm];
}

// 도면 수정 가능 여부 (상태값 + 사용자 레벨)
// 반환: {canEdit:bool, needApproval:bool, reason:string}
function canEditDrawing(statusCode){
  const sc = (typeof statusCode==='string') ? parseInt(statusCode) : statusCode;
  const userLevel = CAD_USER.level;

  // edit 권한 자체가 없으면 불가
  if(!hasPermission('edit')){
    return {canEdit:false, needApproval:false, reason:'수정 권한 없음 (레벨: '+CAD_LEVELS[userLevel]+')'};
  }

  // 상태별 최소 레벨 확인
  const minLevel = CAD_STATUS_EDIT_LEVEL[String(sc)] ?? CAD_STATUS_EDIT_LEVEL[sc];
  if(minLevel !== undefined && userLevel >= minLevel){
    return {canEdit:true, needApproval:false, reason:''};
  }

  // 레벨 부족 → 검수자 승인 필요
  if(minLevel !== undefined && userLevel < minLevel){
    return {canEdit:false, needApproval:true, reason:'검수자 승인 필요 (현재 상태: '+getStatusByCode(sc).label+')'};
  }

  return {canEdit:true, needApproval:false, reason:''};
}

// 뷰어 모드 적용/해제
function setViewerMode(on){
  const tools = document.querySelectorAll('.toolBtn,.ribBtn,.ribBtn2');
  const editFns = ['setTool','undo','redo','deleteSelected','copySelected','pasteObjects','addLayer'];
  if(on){
    document.body.classList.add('viewer-mode');
    notify('뷰어 모드 (읽기 전용)');
  } else {
    document.body.classList.remove('viewer-mode');
  }
}
