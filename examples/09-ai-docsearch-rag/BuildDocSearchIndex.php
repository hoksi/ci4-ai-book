<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * www/docs/Codeigniter4 의 빌드된 HTML 매뉴얼을 스캔해
 * app/Data/docsearch_index.json 검색 인덱스를 만든다.
 *
 * CI4 매뉴얼을 재빌드(sphinx-build)할 때마다 다시 실행해야 한다.
 */
class BuildDocSearchIndex extends BaseCommand
{
    protected $group       = 'Playground';
    protected $name        = 'docsearch:build';
    protected $description = 'CI4 한국어 매뉴얼 HTML을 스캔해 AI 검색 인덱스(json)를 생성합니다.';

    private const SKIP_FILES = ['genindex.html', 'search.html', 'php-modindex.html'];

    public function run(array $params): void
    {
        $docsRoot = ROOTPATH . 'www/docs/Codeigniter4';
        $outFile  = APPPATH . 'Data/docsearch_index.json';

        if (! is_dir($docsRoot)) {
            CLI::error("매뉴얼 디렉터리를 찾을 수 없습니다: {$docsRoot}");
            return;
        }

        $files = $this->collectHtmlFiles($docsRoot);
        CLI::write('HTML 파일 ' . count($files) . '개 발견', 'cyan');

        $docs = [];
        foreach ($files as $file) {
            $doc = $this->extractDoc($docsRoot, $file);
            if ($doc !== null) {
                $docs[] = $doc;
            }
        }

        if (! is_dir(dirname($outFile))) {
            mkdir(dirname($outFile), 0755, true);
        }

        file_put_contents(
            $outFile,
            json_encode($docs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        CLI::write('완료: ' . count($docs) . '개 문서 색인 → ' . str_replace(ROOTPATH, '', $outFile), 'green');
    }

    /** @return string[] */
    private function collectHtmlFiles(string $root): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'html') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/\\');

            if (str_starts_with($relative, '_static') || str_starts_with($relative, '_sources')) {
                continue;
            }

            if (in_array($fileInfo->getFilename(), self::SKIP_FILES, true)) {
                continue;
            }

            $found[] = $fileInfo->getPathname();
        }

        sort($found);

        return $found;
    }

    /** @return array{path:string,title:string,text:string}|null */
    private function extractDoc(string $root, string $file): ?array
    {
        $html = file_get_contents($file);
        if ($html === false || trim($html) === '') {
            return null;
        }

        $relativePath = ltrim(str_replace($root, '', $file), '/\\');
        $relativePath = str_replace('\\', '/', $relativePath);

        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/si', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = preg_replace('/\s*[—\-]\s*CodeIgniter.*$/u', '', $title);
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $main  = $xpath->query('//*[@role="main"]')->item(0);

        if ($main === null) {
            $bodyList = $dom->getElementsByTagName('body');
            $main     = $bodyList->length > 0 ? $bodyList->item(0) : null;
        }

        if ($main === null) {
            return null;
        }

        foreach (['script', 'style'] as $tag) {
            $nodes = $main->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node->parentNode->removeChild($node);
            }
        }

        $text = $main->textContent;
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if ($text === '' || mb_strlen($text) < 30) {
            return null;
        }

        return [
            'path'  => $relativePath,
            'title' => $title !== '' ? $title : $relativePath,
            'text'  => mb_substr($text, 0, 8000),
        ];
    }
}
