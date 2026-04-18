<?php
declare(strict_types=1);

require_once __DIR__ . '/StorageDriver.php';

/**
 * StorageService
 *
 * SaaS 멀티테넌트 파일 스토리지 서비스.
 * StorageDriver(마운트 드라이브)를 감싸 카테고리·테넌트 기반 경로를 관리한다.
 *
 * ── 경로 구조 ──────────────────────────────────────
 *  서버 물리경로: D:/SHV_ERP/SHVQ_V2/uploads/{tenant_id}/{category}/{filename}
 *  HTTP URL:     https://shvq.kr/SHVQ_V2/uploads/{tenant_id}/{category}/{filename}
 *
 * ── V1 레거시 이미지 ────────────────────────────────
 *  서버 물리경로: D:/SHV_ERP/SHVQ_V2/uploads/mat/{filename}  (V1→V2 복사 완료)
 *  HTTP URL:     https://shvq.kr/SHVQ_V2/uploads/mat/{filename}
 *
 * ── 사용 예 ────────────────────────────────────────
 *  // 신규 업로드
 *  $storage = StorageService::forTenant($tenantId);
 *  $result  = $storage->upload('mat_banner', $_FILES['banner'], "item_{$idx}");
 *  echo $result['url'];      // https://shvq.kr/SHVQ_V2/uploads/42/mat/item_11_20260413120000.jpg
 *  echo $result['filename']; // item_11_20260413120000.jpg
 *
 *  // V1 이관 이미지 URL (upload_files_banner / upload_files_detail 컬럼값)
 *  echo StorageService::legacyUrl($row['upload_files_banner']);
 *  // → https://shvq.kr/SHVQ_V2/uploads/mat/11_upload_files_banner_20231012.png
 */
class StorageService
{
    /** 카테고리 → 드라이브 상대경로 + URL 경로 매핑 */
    private const CATEGORIES = [
        'mat_banner'  => 'mat',
        'mat_detail'  => 'mat',
        'mat_attach'  => 'mat/attach',
        'mail_attach' => 'mail/attach',
        'mail_inline' => 'mail/inline',
        'employee'    => 'employee',
        'common'      => 'common',
    ];

    private StorageDriver $driver;
    private int           $tenantId;
    private string        $baseUrl;
    private array         $categories;

    // ──────────────────────────────────────────
    //  생성자 / 팩토리
    // ──────────────────────────────────────────

    public function __construct(StorageDriver $driver, int $tenantId, string $baseUrl, array $categories = [])
    {
        $this->driver   = $driver;
        $this->tenantId = $tenantId;
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->categories = is_array($categories) ? $categories : [];
    }

    /**
     * 테넌트 ID로 서비스 인스턴스 생성 (config/storage.php 자동 로드)
     */
    public static function forTenant(int $tenantId): self
    {
        $cfg    = require __DIR__ . '/../../../config/storage.php';
        $driver = StorageDriver::fromConfig($cfg['driver']);
        return new self(
            $driver,
            $tenantId,
            (string)($cfg['base_url'] ?? ''),
            (array)($cfg['categories'] ?? [])
        );
    }

    // ──────────────────────────────────────────
    //  업로드
    // ──────────────────────────────────────────

    /**
     * $_FILES 항목 업로드
     *
     * @param string $category    카테고리 키 (mat_banner, mail_attach …)
     * @param array  $phpFile     $_FILES['field'] 단일 파일 배열
     * @param string $prefix      파일명 앞에 붙일 prefix  (예: "item_11")
     * @return array{filename: string, path: string, url: string, size: int, mime: string}
     * @throws InvalidArgumentException  유효성 오류
     * @throws RuntimeException          저장 실패
     */
    public function upload(string $category, array $phpFile, string $prefix = ''): array
    {
        $this->validateFile($phpFile);

        $ext      = strtolower(pathinfo((string)($phpFile['name'] ?? ''), PATHINFO_EXTENSION));
        $filename = $this->makeFilename($prefix, $ext);
        $remote   = $this->remotePath($category, $filename);

        $this->driver->put($remote, (string)($phpFile['tmp_name'] ?? ''));

        return [
            'filename' => $filename,
            'path'     => $remote,
            'url'      => $this->urlFor($category, $filename),
            'size'     => (int)($phpFile['size'] ?? 0),
            'mime'     => (string)($phpFile['type'] ?? ''),
        ];
    }

    /**
     * upload() 별칭
     *
     * @return array{filename: string, path: string, url: string, size: int, mime: string}
     */
    public function put(string $category, array $phpFile, string $prefix = ''): array
    {
        return $this->upload($category, $phpFile, $prefix);
    }

    /**
     * 임시 파일 경로로 직접 업로드 (API 내부용)
     *
     * @return array{filename: string, path: string, url: string}
     * @throws RuntimeException
     */
    public function uploadRaw(
        string $category,
        string $tmpPath,
        string $originalName,
        string $prefix = ''
    ): array {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $filename = $this->makeFilename($prefix, $ext);
        $remote   = $this->remotePath($category, $filename);

        $this->driver->put($remote, $tmpPath);

        return [
            'filename' => $filename,
            'path'     => $remote,
            'url'      => $this->urlFor($category, $filename),
        ];
    }

    // ──────────────────────────────────────────
    //  삭제 / 조회
    // ──────────────────────────────────────────

    /**
     * 파일 삭제 (없으면 무시)
     *
     * @throws RuntimeException
     */
    public function delete(string $category, string $filename): void
    {
        $this->driver->delete($this->remotePath($category, $filename));
    }

    /**
     * 파일 존재 여부
     */
    public function exists(string $category, string $filename): bool
    {
        return $this->driver->exists($this->remotePath($category, $filename));
    }

    /**
     * HTTP URL 반환
     */
    public function url(string $category, string $filename): string
    {
        return $this->urlFor($category, $filename);
    }

    // ──────────────────────────────────────────
    //  V1 레거시 호환
    // ──────────────────────────────────────────

    /**
     * V1 /product/ 폴더 이미지의 HTTP URL 반환 (테넌트 무관)
     *
     * @param string $filename  예: 11_banner_20260324025743.png
     */
    public static function legacyUrl(string $filename): string
    {
        // V1 이미지는 V2 uploads/mat/ 으로 복사됨 → shvq.kr 에서 직접 서빙
        return 'https://shvq.kr/SHVQ_V2/uploads/mat/' . $filename;
    }

    // ──────────────────────────────────────────
    //  내부 헬퍼
    // ──────────────────────────────────────────

    /**
     * 원격 경로 조립
     * 패턴: uploads/{tenant_id}/{categorySubPath}/{filename}
     */
    private function remotePath(string $category, string $filename): string
    {
        $sub = $this->resolveCategorySubPath($category);
        return "uploads/{$this->tenantId}/{$sub}/{$filename}";
    }

    /**
     * HTTP URL 조립
     */
    private function urlFor(string $category, string $filename): string
    {
        $sub = $this->resolveCategorySubPath($category);
        return "{$this->baseUrl}/uploads/{$this->tenantId}/{$sub}/{$filename}";
    }

    private function resolveCategorySubPath(string $category): string
    {
        $mapped = $this->categories[$category] ?? self::CATEGORIES[$category] ?? $category;
        $mapped = trim(str_replace('\\', '/', (string)$mapped), '/');
        return $mapped !== '' ? $mapped : 'common';
    }

    /**
     * 유니크 파일명 생성
     * 패턴: {prefix}_{YmdHis}_{uniq4}.{ext}
     */
    private function makeFilename(string $prefix, string $ext): string
    {
        $ts   = date('YmdHis');
        $uniq = substr(bin2hex(random_bytes(2)), 0, 4);
        $name = $prefix !== '' ? "{$prefix}_{$ts}_{$uniq}" : "{$ts}_{$uniq}";
        return $ext !== '' ? "{$name}.{$ext}" : $name;
    }

    /**
     * $_FILES 파일 유효성 검사
     *
     * @throws InvalidArgumentException
     */
    private function validateFile(array $file): void
    {
        $cfg = require __DIR__ . '/../../../config/storage.php';

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => '파일이 서버 허용 크기를 초과합니다.',
                UPLOAD_ERR_FORM_SIZE  => '파일이 폼 허용 크기를 초과합니다.',
                UPLOAD_ERR_PARTIAL    => '파일이 일부만 업로드되었습니다.',
                UPLOAD_ERR_NO_FILE    => '파일이 업로드되지 않았습니다.',
                UPLOAD_ERR_NO_TMP_DIR => '임시 폴더가 없습니다.',
                UPLOAD_ERR_CANT_WRITE => '파일 저장에 실패했습니다.',
            ];
            throw new InvalidArgumentException($messages[$error] ?? "업로드 오류 코드: {$error}");
        }

        $size    = (int)($file['size'] ?? 0);
        $maxSize = (int)($cfg['limits']['max_size_bytes'] ?? 20 * 1024 * 1024);
        if ($size > $maxSize) {
            $mb = round($maxSize / 1024 / 1024, 1);
            throw new InvalidArgumentException("파일 크기가 허용 한도({$mb}MB)를 초과합니다.");
        }

        $ext     = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = (array)($cfg['limits']['allowed_ext'] ?? []);
        if ($ext === '' || (!empty($allowed) && !in_array($ext, $allowed, true))) {
            throw new InvalidArgumentException("허용되지 않는 확장자입니다: .{$ext}");
        }
    }
}
