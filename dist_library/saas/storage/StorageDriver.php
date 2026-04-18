<?php
declare(strict_types=1);

/**
 * StorageDriver
 *
 * FTP 드라이브(E:\)가 서버에 로컬 드라이브로 마운트된 환경에서
 * PHP 표준 파일 함수(copy / unlink / mkdir)로 파일을 관리한다.
 *
 * 경로 규칙
 *   - $remotePath : 드라이브 루트 기준 상대 경로  (예: product/banner.png)
 *   - 실제 경로   : {basePath}/{remotePath}       (예: E:/product/banner.png)
 *
 * 사용 예)
 *   $driver = StorageDriver::fromConfig($cfg['storage']['driver']);
 *   $driver->put('product/img.png', '/tmp/upload_abc');
 *   $driver->delete('product/img.png');
 *   echo $driver->exists('product/img.png'); // true|false
 */
class StorageDriver
{
    /** @var string 드라이브 루트 절대 경로 (끝 슬래시 포함, 예: E:/) */
    private string $basePath;

    // ──────────────────────────────────────────
    //  생성자 / 팩토리
    // ──────────────────────────────────────────

    public function __construct(string $basePath)
    {
        // 끝에 슬래시 보정
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
    }

    /**
     * config/storage.php 의 'driver' 섹션으로 인스턴스 생성
     *
     * @param array{base_path: string} $cfg
     */
    public static function fromConfig(array $cfg): self
    {
        return new self((string)($cfg['base_path'] ?? 'E:/'));
    }

    // ──────────────────────────────────────────
    //  파일 작업
    // ──────────────────────────────────────────

    /**
     * 로컬 임시 파일 → 드라이브 업로드
     *
     * @param string $remotePath  드라이브 루트 기준 경로 (예: product/banner.png)
     * @param string $localPath   PHP 임시 파일 경로    (예: /tmp/phpXXXXXX)
     * @throws RuntimeException
     */
    public function put(string $remotePath, string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("소스 파일이 존재하지 않습니다: {$localPath}");
        }

        $dest = $this->resolve($remotePath);
        $this->ensureDir(dirname($dest));

        if (!copy($localPath, $dest)) {
            throw new RuntimeException("파일 저장 실패: {$dest}");
        }
    }

    /**
     * 드라이브 파일 → 로컬 경로 복사 (다운로드)
     *
     * @throws RuntimeException
     */
    public function get(string $remotePath, string $localPath): void
    {
        $src = $this->resolve($remotePath);

        if (!is_file($src)) {
            throw new RuntimeException("파일이 존재하지 않습니다: {$src}");
        }

        $this->ensureDir(dirname($localPath));

        if (!copy($src, $localPath)) {
            throw new RuntimeException("파일 복사 실패: {$src} → {$localPath}");
        }
    }

    /**
     * 드라이브 파일 삭제
     *
     * 파일이 없으면 예외 없이 무시 (idempotent)
     *
     * @throws RuntimeException  실제 삭제 시도 중 실패
     */
    public function delete(string $remotePath): void
    {
        $path = $this->resolve($remotePath);

        if (!file_exists($path)) {
            return; // 이미 없으면 OK
        }

        if (!unlink($path)) {
            throw new RuntimeException("파일 삭제 실패: {$path}");
        }
    }

    /**
     * 파일 존재 여부 확인
     */
    public function exists(string $remotePath): bool
    {
        return is_file($this->resolve($remotePath));
    }

    /**
     * 파일 크기 (바이트). 파일이 없으면 -1
     */
    public function size(string $remotePath): int
    {
        $path = $this->resolve($remotePath);
        return is_file($path) ? (int)filesize($path) : -1;
    }

    /**
     * 디렉토리 내 파일명 목록 반환
     *
     * @return string[]
     */
    public function listDir(string $remotePath = ''): array
    {
        $path = $this->resolve($remotePath);

        if (!is_dir($path)) {
            return [];
        }

        $items = scandir($path);
        if ($items === false) {
            return [];
        }

        return array_values(array_filter($items, static fn (string $f): bool => $f !== '.' && $f !== '..'));
    }

    // ──────────────────────────────────────────
    //  디렉토리 작업
    // ──────────────────────────────────────────

    /**
     * 디렉토리 재귀 생성 (이미 존재하면 무시)
     *
     * @throws RuntimeException
     */
    public function mkdir(string $remotePath): void
    {
        $this->ensureDir($this->resolve($remotePath));
    }

    // ──────────────────────────────────────────
    //  내부 헬퍼
    // ──────────────────────────────────────────

    /**
     * remotePath → 실제 절대 경로
     *
     * 보안: ../ 경로 트래버설 차단
     */
    private function resolve(string $remotePath): string
    {
        // 슬래시 통일, 앞 슬래시 제거
        $clean = ltrim(str_replace(['\\', '../', '..\\'], ['/', '', ''], $remotePath), '/');
        return $this->basePath . $clean;
    }

    /**
     * 디렉토리가 없으면 재귀 생성
     *
     * @throws RuntimeException
     */
    private function ensureDir(string $absPath): void
    {
        if (is_dir($absPath)) {
            return;
        }

        if (!mkdir($absPath, 0755, true) && !is_dir($absPath)) {
            throw new RuntimeException("디렉토리 생성 실패: {$absPath}");
        }
    }

    // ──────────────────────────────────────────
    //  정보 조회
    // ──────────────────────────────────────────

    /** 현재 basePath 반환 */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
