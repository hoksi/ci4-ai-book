# 9장. AI 문서 검색봇(RAG) 만들기

## 이번 장에서 만드는 것

사용자가 "파일 업로드는 어떻게 하나요?"라고 물으면, CI4 한국어 매뉴얼 321개 문서 중 관련 있는 페이지를 찾아 그 내용을 근거로 AI가 답변하고, 답변 아래에 실제로 참고한 매뉴얼 페이지 링크까지 붙여주는 기능입니다.

이런 식으로 "LLM이 가진 지식"이 아니라 "우리가 가진 문서"를 근거로 답하게 만드는 방식을 **RAG**(Retrieval-Augmented Generation)라고 부릅니다. 거창하게 들리지만, 이번 장에서 만들 건 딱 세 단계입니다.

1. 매뉴얼 HTML을 미리 검색 가능한 형태로 인덱싱해둔다
2. 질문이 들어오면 관련 문서를 점수로 찾는다
3. 찾은 문서 내용을 컨텍스트로 넣어서 AI에게 "이 안에서만 답하라"고 시킨다

## 왜 벡터 DB 없이 키워드 검색인가

RAG라고 하면 보통 임베딩(embedding) + 벡터 DB(Pinecone, Weaviate 등)를 먼저 떠올립니다. 의미 기반 검색이 가능해서 "파일 업로드"와 "첨부파일 전송"처럼 단어는 다르지만 뜻이 비슷한 문서도 찾아주기 때문입니다.

다만 이 튜토리얼은 **공유 호스팅(cafe24 같은 php-cli도 막힌 환경)에 그대로 배포**하는 게 목표입니다. 외부 벡터 DB를 추가로 계약하거나 임베딩 API 호출 비용을 또 들이는 대신, "제목에 가중치를 주고 부분 문자열로 점수를 매기는" 아주 단순한 방식만으로도 **문서 321개 규모**에서는 충분히 쓸만한 결과가 나옵니다. 규모가 커지면(수만 건 이상) 그때 임베딩 검색으로 바꾸는 게 맞지만, 작은 프로젝트에서 처음부터 무거운 인프라를 끌어오는 건 과합니다.

## 1단계: 검색 인덱스 만들기 (커스텀 spark 커맨드)

매 요청마다 HTML 321개를 다시 읽고 파싱하면 느립니다. 그래서 **미리 한 번 스캔해서 JSON 파일로 저장**해두고, 실제 요청 때는 이 JSON만 읽습니다. CI4의 `BaseCommand`를 상속하면 나만의 `spark` 명령어를 만들 수 있습니다.

```php
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

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

        $files = $this->collectHtmlFiles($docsRoot);
        CLI::write('HTML 파일 ' . count($files) . '개 발견', 'cyan');

        $docs = [];
        foreach ($files as $file) {
            $doc = $this->extractDoc($docsRoot, $file);
            if ($doc !== null) {
                $docs[] = $doc;
            }
        }

        file_put_contents(
            $outFile,
            json_encode($docs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        CLI::write('완료: ' . count($docs) . '개 문서 색인', 'green');
    }
}
```

`$name`에 지정한 문자열이 곧 명령어입니다. 저장 후 바로 실행할 수 있습니다.

```bash
php spark docsearch:build
```

### HTML에서 본문만 뽑아내기

Sphinx로 빌드된 매뉴얼 HTML은 내비게이션/사이드바/검색창 같은 군더더기가 잔뜩 붙어 있습니다. 전부 다 텍스트로 넣으면 검색 품질이 떨어지므로, 실제 본문이 들어있는 `role="main"` 영역만 `DOMDocument` + `DOMXPath`로 골라냅니다.

```php
private function extractDoc(string $root, string $file): ?array
{
    $html = file_get_contents($file);

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

    foreach (['script', 'style'] as $tag) {
        $nodes = $main->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $main->removeChild($nodes->item($i));
        }
    }

    $text = preg_replace('/\s+/u', ' ', $main->textContent);

    return [
        'path'  => /* HTML 파일의 상대 경로 */,
        'title' => $title,
        'text'  => mb_substr(trim($text), 0, 8000),
    ];
}
```

포인트 두 가지입니다.

- `libxml_use_internal_errors(true)`: Sphinx HTML에는 `DOMDocument`가 싫어하는 HTML5 태그가 섞여 있어서, 경고를 끄지 않으면 파싱할 때마다 PHP 경고가 쏟아집니다.
- `<title>` 태그에서 " — CodeIgniter 4.7.5 문서" 같은 꼬리표를 정규식으로 잘라내서, 검색할 때 순수한 페이지 제목만 가중치에 쓰도록 합니다.

이렇게 만든 `docs` 배열을 JSON으로 저장하면 끝입니다. 매뉴얼을 새로 빌드할 때마다 `php spark docsearch:build`를 다시 실행하면 인덱스가 최신 상태로 갱신됩니다.

## 2단계: 질문을 토큰으로 쪼개기

사용자 질문 "파일 업로드는 어떻게 하나요?"를 그대로 검색에 쓰면 거의 안 걸립니다. 매뉴얼 본문에는 "파일 업로드는"이라는 정확한 문자열이 없고 "파일 업로드"만 있기 때문입니다. 한국어는 명사에 조사(는/은/이/가/을/를 등)가 바로 붙어버리는 교착어라서, 영어처럼 공백으로 쪼개는 것만으로는 부족합니다.

형태소 분석기(은전한닢, mecab-ko 등)를 붙이면 정확하지만, 이 프로젝트 규모에 그 정도 의존성을 추가하긴 부담스럽습니다. 대신 아주 거친 트릭을 씁니다 — **단어 끝 1~2글자를 떼어낸 버전도 약한 가중치로 같이 검색**하는 겁니다.

```php
private function tokenize(string $question): array
{
    $raw = preg_split('/[\s,.\?!\/\\\\·"\'()\[\]{}]+/u', $question, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = [];

    foreach ($raw as $word) {
        $word = mb_strtolower($word);
        if (mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
            continue;
        }

        $tokens[$word] = max($tokens[$word] ?? 0, 1.0);

        $len = mb_strlen($word);
        if ($len >= 4) {
            $tokens[mb_substr($word, 0, $len - 1)] = max($tokens[mb_substr($word, 0, $len - 1)] ?? 0, 0.6);
        }
        if ($len >= 5) {
            $tokens[mb_substr($word, 0, $len - 2)] = max($tokens[mb_substr($word, 0, $len - 2)] ?? 0, 0.4);
        }
    }

    return $tokens;
}
```

"업로드는"(4글자) → 원본 1.0, "업로드"(3글자, 끝 1글자 제거) 0.6 가중치로 함께 등록됩니다. "업로드"는 매뉴얼 본문에 실제로 있는 문자열이라 검색에 걸립니다. 완벽한 형태소 분석은 아니지만, 흔한 조사 패턴은 꽤 잘 걸러집니다.

`STOPWORDS`에는 "어떻게", "방법", "알려주세요" 같은, 질문에는 자주 나오지만 매뉴얼 본문과 매칭될 일이 없는 단어들을 넣어 애초에 토큰화 대상에서 제외합니다.

## 3단계: 점수로 관련 문서 찾기

이제 토큰별 가중치를 가지고 321개 문서 전체를 훑으면서 점수를 매깁니다. **제목에 매칭되면 본문보다 5배 가중치**를 주는 게 핵심입니다. "파일 업로드"라는 단어가 본문 어딘가에 스쳐 지나가듯 한 번 나온 문서보다, 제목 자체가 "파일 업로드"인 문서가 훨씬 더 관련 있을 확률이 높기 때문입니다.

```php
private function searchDocs(string $question): array
{
    $tokens = $this->tokenize($question);
    $results = [];

    foreach ($index as $doc) {
        $titleLower = mb_strtolower($doc['title']);
        $textLower  = mb_strtolower($doc['text']);
        $score = 0.0;

        foreach ($tokens as $tok => $weight) {
            $titleHits = substr_count($titleLower, $tok);
            $textHits  = substr_count($textLower, $tok);
            $score += ($titleHits * 5 + $textHits) * $weight;
        }

        if ($score > 0) {
            $results[] = ['doc' => $doc, 'score' => $score];
        }
    }

    usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($results, 0, self::TOP_N); // 상위 5개
}
```

`substr_count`는 PHP 내장 함수치고 생각보다 똑똑합니다. UTF-8 문자열이어도 "부분 문자열이 몇 번 나오는지"를 바이트 단위로 정확히 세어주기 때문에, 한글 텍스트에도 그대로 쓸 수 있습니다.

## 4단계: 컨텍스트 만들어서 Groq에 근거 기반으로 질의하기

상위 5개 문서를 찾았다고 전체 본문(최대 8000자씩)을 통째로 AI에 넘기면 토큰 낭비가 심합니다. 그래서 **매칭된 단어 근처만 잘라서** 스니펫으로 만듭니다.

```php
private function buildContext(array $matches): array
{
    $context = '';
    $sources = [];

    foreach ($matches as $m) {
        $doc = $m['doc'];
        $pos = mb_stripos($doc['text'], $m['bestToken']);
        $snippet = $pos !== false
            ? mb_substr($doc['text'], max(0, $pos - 150), 900)
            : mb_substr($doc['text'], 0, 900);

        $block = "### {$doc['title']}\n{$snippet}\n\n";
        if (mb_strlen($context) + mb_strlen($block) > self::CONTEXT_MAX) {
            break;
        }

        $context  .= $block;
        $sources[] = ['title' => $doc['title'], 'path' => $doc['path'], 'url' => base_url('docs/Codeigniter4/' . $doc['path'])];
    }

    return [$context, $sources];
}
```

그리고 이 컨텍스트를 **시스템 프롬프트에 강하게 못 박아서** 넣습니다. 이 부분이 RAG에서 가장 중요한 지점입니다 — 아무리 좋은 검색 결과를 넘겨도, AI에게 "이 내용만 보고 답해"라고 명시적으로 지시하지 않으면 자기가 학습한 지식과 뒤섞어서 그럴듯하지만 틀린 답(할루시네이션)을 만들어냅니다.

```php
$systemPrompt = <<<PROMPT
    당신은 CodeIgniter 4 한국어 공식 매뉴얼 기반 Q&A 도우미입니다.
    아래 [매뉴얼 발췌]에 있는 내용만 근거로 한국어로 답변하세요.
    발췌 내용에 답이 없으면 "매뉴얼에서 관련 내용을 찾지 못했습니다"라고 답하세요.
    코드 예제가 있다면 포함하고, 4~6문장 이내로 간결하게 답변하세요.

    [매뉴얼 발췌]
    {$context}
    PROMPT;
```

이후 Groq 호출은 7장(AI 챗봇)에서 만든 것과 거의 같은 패턴입니다. `temperature`만 0.3으로 낮춰서(챗봇은 0.8) 답변이 더 보수적이고 사실 기반으로 나오게 합니다.

## 5단계: 답변 + 출처 반환하기

마지막으로 AI 답변과 함께 "어떤 문서를 참고했는지" 출처 링크를 같이 내려줍니다. 사용자가 답변을 믿을지 말지 스스로 검증할 수 있게 해주는, RAG에서 빠지면 안 되는 부분입니다.

```php
return $this->response->setJSON([
    'success'  => true,
    'question' => $question,
    'answer'   => $answer,
    'sources'  => $sources, // [{title, path, url}, ...]
]);
```

질문/답변/출처는 `doc_search_logs` 테이블에도 저장해서, 페이지를 다시 열어도 이전 질문 내역을 볼 수 있게 했습니다.

## 한계와 개선 방향

- **조사 트리밍은 휴리스틱일 뿐입니다.** "먹었습니다" 같은 동사 활용형은 전혀 처리하지 못합니다. 정확도가 더 필요하면 `mecab-ko` 같은 형태소 분석기를 붙이는 걸 고려하세요.
- **"의미"는 못 찾습니다.** 키워드 검색이라 "파일 업로드"와 완전히 동의어가 아닌 "첨부파일 전송" 같은 표현은 놓칠 수 있습니다. 문서량이 많아지면(수만 건 이상) 임베딩 기반 벡터 검색으로 전환을 고려할 시점입니다.
- **AI 호출이 실패하면?** Groq 쿼터 초과(429) 같은 상황은 지금 코드에서 사용자에게 암호 같은 에러 메시지로 그대로 노출됩니다. 이 문제는 11장에서 제대로 고칩니다.
- **답변에 마크다운이 섞여 나옵니다.** Groq는 `**굵게**`, 번호 목록, 코드블록을 즐겨 씁니다. 이걸 그대로 이스케이프해서 보여주면 기호가 날것으로 보입니다 — 12장에서 안전하게 렌더링하는 법을 다룹니다.

## 전체 코드

이번 장에 나온 두 파일의 전체 원본은 [`examples/09-ai-docsearch-rag/`](../examples/09-ai-docsearch-rag/)에서 그대로 받아볼 수 있습니다. 본문에는 설명을 위해 일부만 발췌했지만, 거기엔 전체 코드와 자신의 프로젝트에 적용하는 방법까지 정리해뒀습니다.

다음 장에서는 이 기능을 실시간 스트리밍으로 바꿔서, 답변이 한 번에 뚝 떨어지는 대신 타이핑되듯 보이게 만들어보겠습니다.
