# 8장. AI 게시판 검색봇 만들기 (RAG)

## 이번 장에서 만드는 것

사용자가 "고양이 키우기 관련 글 있어?"라고 물으면, 게시판에 쌓인 글 중 관련 있는 글을 찾아 그 내용을 근거로 AI가 답변하고, 답변 아래에 실제로 참고한 게시글 링크까지 붙여주는 기능입니다.

이런 식으로 "LLM이 가진 지식"이 아니라 "우리가 가진 데이터"를 근거로 답하게 만드는 방식을 **RAG**(Retrieval-Augmented Generation)라고 부릅니다. 거창하게 들리지만, 이번 장에서 만들 건 딱 세 단계입니다.

1. 질문이 들어오면 관련 있는 게시글을 점수로 찾는다
2. 찾은 글의 내용을 컨텍스트로 넣어서 AI에게 "이 안에서만 답하라"고 시킨다
3. 답변과 함께 실제로 참고한 게시글 링크를 돌려준다

## 왜 벡터 DB 없이 키워드 검색인가

RAG라고 하면 보통 임베딩(embedding) + 벡터 DB(Pinecone, Weaviate 등)를 먼저 떠올립니다. 의미 기반 검색이 가능해서 단어는 다르지만 뜻이 비슷한 글도 찾아주기 때문입니다.

다만 이 튜토리얼은 **공유 호스팅(13장에서 다루는 cafe24 같은, php-cli도 막힌 환경)에 그대로 배포**하는 게 목표입니다. 외부 벡터 DB를 추가로 계약하거나 임베딩 API 호출 비용을 또 들이는 대신, "제목에 가중치를 주고 부분 문자열로 점수를 매기는" 아주 단순한 방식만으로도 **커뮤니티 게시판 규모**에서는 충분히 쓸만한 결과가 나옵니다. 글이 수만 건 이상으로 늘어나면 그때 임베딩 검색으로 바꾸는 게 맞지만, 작은 게시판에서 처음부터 무거운 인프라를 끌어오는 건 과합니다.

## 왜 미리 인덱스를 만들지 않는가

이 장의 초안을 CI4 공식 매뉴얼 검색봇으로 설계했을 때는, 매뉴얼이 Sphinx로 빌드할 때만 바뀌는 **정적 데이터**였기 때문에 "미리 한 번 스캔해서 JSON 인덱스로 저장해두고, 매뉴얼이 바뀔 때만 다시 빌드"하는 방식이 잘 맞았습니다.

게시판 글은 다릅니다. 사용자가 글을 쓸 때마다 계속 늘어나는 **동적 데이터**라서, 미리 만들어둔 인덱스 파일은 새 글이 올라오는 순간 바로 낡아버립니다. 그래서 이번 장은 더 단순하게 갑니다 — **질문이 들어올 때마다 승인된 게시글을 직접 조회해서 그 자리에서 점수를 매깁니다.** 커뮤니티 게시판 규모(수백\~수천 건)에서는 이 정도로 충분하고, 커스텀 `spark` 커맨드나 HTML 파싱 같은 추가 인프라가 전혀 필요 없어집니다.

```php
$posts = (new PostModel())
    ->where('spam_status', 'approved')
    ->findAll();
```

`spam_status = 'approved'` 조건을 눈여겨보세요 — **7장에서 만든 스팸 필터 컬럼을 그대로 재사용**합니다. 스팸으로 분류됐거나 아직 관리자 검토 중인 글(`review`)은 검색 대상에서 자동으로 빠집니다.

## 1단계: 질문을 토큰으로 쪼개기

사용자 질문 "고양이 키우기 관련 글 있어?"를 그대로 검색에 쓰면 거의 안 걸립니다. 게시글 본문에는 "키우기 관련"이라는 정확한 문자열이 없고 "키우기"만 있을 수 있기 때문입니다. 한국어는 명사에 조사(는/은/이/가/을/를 등)가 바로 붙어버리는 교착어라서, 영어처럼 공백으로 쪼개는 것만으로는 부족합니다.

형태소 분석기(은전한닢, mecab-ko 등)를 붙이면 정확하지만, 이 프로젝트 규모에 그 정도 의존성을 추가하긴 부담스럽습니다. 대신 아주 거친 트릭을 씁니다 — **단어 끝 1\~2글자를 떼어낸 버전도 약한 가중치로 같이 검색**하는 겁니다.

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

"키우기는"(4글자) → 원본 1.0, "키우기"(3글자, 끝 1글자 제거) 0.6 가중치로 함께 등록됩니다. 완벽한 형태소 분석은 아니지만, 흔한 조사 패턴은 꽤 잘 걸러집니다. `STOPWORDS`에는 "어떻게", "방법", "알려주세요" 같은, 질문에는 자주 나오지만 게시글 본문과 매칭될 일이 없는 단어들을 넣어 애초에 토큰화 대상에서 제외합니다.

## 2단계: 점수로 관련 게시글 찾기

토큰별 가중치를 가지고 승인된 게시글 전체를 훑으면서 점수를 매깁니다. **제목에 매칭되면 본문보다 5배 가중치**를 주는 게 핵심입니다. "고양이"라는 단어가 본문 어딘가에 스쳐 지나가듯 한 번 나온 글보다, 제목 자체가 "고양이 키우기 꿀팁"인 글이 훨씬 더 관련 있을 확률이 높기 때문입니다.

```php
private function searchPosts(string $question): array
{
    $tokens = $this->tokenize($question);
    $posts  = (new PostModel())->where('spam_status', 'approved')->findAll();
    $results = [];

    foreach ($posts as $post) {
        $titleLower = mb_strtolower($post->title);
        $textLower  = mb_strtolower($post->content);
        $score = 0.0;

        foreach ($tokens as $tok => $weight) {
            $titleHits = substr_count($titleLower, $tok);
            $textHits  = substr_count($textLower, $tok);
            $score += ($titleHits * 5 + $textHits) * $weight;
        }

        if ($score > 0) {
            $results[] = ['post' => $post, 'score' => $score];
        }
    }

    usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($results, 0, self::TOP_N); // 상위 5개
}
```

`$post`가 4장에서 만든 `PostModel`의 Entity 객체라서 `$post->title`, `$post->content`로 바로 접근할 수 있습니다. `substr_count`는 UTF-8 문자열에서도 부분 문자열이 몇 번 나오는지 바이트 단위로 정확히 세어주기 때문에, 한글 텍스트에도 그대로 쓸 수 있습니다.

## 3단계: 컨텍스트 만들어서 Groq에 근거 기반으로 질의하기

상위 5개 글을 찾았다고 전체 본문을 통째로 AI에 넘기면 토큰 낭비가 심합니다. 그래서 **매칭된 단어 근처만 잘라서** 스니펫으로 만듭니다.

```php
private function buildContext(array $matches): array
{
    $context = '';
    $sources = [];

    foreach ($matches as $m) {
        $post = $m['post'];
        $pos  = mb_stripos($post->content, $m['bestToken']);
        $snippet = $pos !== false
            ? mb_substr($post->content, max(0, $pos - 150), 900)
            : mb_substr($post->content, 0, 900);

        $block = "### {$post->title}\n{$snippet}\n\n";
        if (mb_strlen($context) + mb_strlen($block) > self::CONTEXT_MAX) {
            break;
        }

        $context  .= $block;
        $sources[] = ['title' => $post->title, 'id' => $post->id, 'url' => base_url('board/' . $post->id)];
    }

    return [$context, $sources];
}
```

그리고 이 컨텍스트를 **시스템 프롬프트에 강하게 못 박아서** 넣습니다. 이 부분이 RAG에서 가장 중요한 지점입니다 — 아무리 좋은 검색 결과를 넘겨도, AI에게 "이 내용만 보고 답해"라고 명시적으로 지시하지 않으면 자기가 학습한 지식과 뒤섞어서 그럴듯하지만 틀린 답(할루시네이션)을 만들어냅니다.

```php
$systemPrompt = <<<PROMPT
    당신은 게시판 글 기반 Q&A 도우미입니다.
    아래 [게시글 발췌]에 있는 내용만 근거로 한국어로 답변하세요.
    발췌 내용에 답이 없으면 "게시판에서 관련 글을 찾지 못했습니다"라고 답하세요.
    4~6문장 이내로 간결하게 답변하세요.

    [게시글 발췌]
    {$context}
    PROMPT;
```

이후 Groq 호출은 6장에서 소개한 기본 패턴 그대로입니다. `temperature`만 0.3으로 낮춰서 답변이 더 보수적이고 사실 기반으로 나오게 합니다 (자유로운 대화가 목적인 챗봇은 반대로 0.8을 씁니다 — 9장에서 다룹니다).

## 4단계: 답변 + 출처 반환하기

마지막으로 AI 답변과 함께 "어떤 글을 참고했는지" 출처 링크를 같이 내려줍니다. 사용자가 답변을 믿을지 말지 스스로 검증할 수 있게 해주는, RAG에서 빠지면 안 되는 부분입니다.

```php
return $this->response->setJSON([
    'success'  => true,
    'question' => $question,
    'answer'   => $answer,
    'sources'  => $sources, // [{title, id, url}, ...] — 게시글 상세 페이지 링크
]);
```

질문/답변/출처는 `board_search_logs` 테이블에도 저장해서, 페이지를 다시 열어도 이전 질문 내역을 볼 수 있게 했습니다.

## 한계와 개선 방향

- **조사 트리밍은 휴리스틱일 뿐입니다.** "먹었습니다" 같은 동사 활용형은 전혀 처리하지 못합니다. 정확도가 더 필요하면 `mecab-ko` 같은 형태소 분석기를 붙이는 걸 고려하세요.
- **게시글이 많아지면 매 요청마다 전체를 훑는 비용이 커집니다.** 수백\~수천 건 규모까지는 괜찮지만, 그 이상 커지면 결과를 짧게 캐시하거나(6장의 `cache()` 패턴), DB의 `LIKE`/전문 검색(FULLTEXT) 인덱스로 1차 후보를 좁힌 뒤 점수를 매기는 식으로 최적화할 수 있습니다.
- **"의미"는 못 찾습니다.** 키워드 검색이라 "고양이 키우기"와 완전히 동의어가 아닌 "반려묘 입양" 같은 표현은 놓칠 수 있습니다. 게시글이 아주 많아지면 임베딩 기반 벡터 검색으로 전환을 고려할 시점입니다.
- **AI 호출이 실패하면?** Groq 쿼터 초과(429) 같은 상황은 지금 코드에서 사용자에게 암호 같은 에러 메시지로 그대로 노출됩니다. 이 문제는 11장에서 제대로 고칩니다.
- **답변에 마크다운이 섞여 나옵니다.** Groq는 `**굵게**`, 번호 목록, 코드블록을 즐겨 씁니다. 이걸 그대로 이스케이프해서 보여주면 기호가 날것으로 보입니다 — 12장에서 안전하게 렌더링하는 법을 다룹니다.

## 전체 코드

이번 장에 나온 코드의 전체 원본은 [`examples/08-ai-board-search/`](../examples/08-ai-board-search/)에서 그대로 받아볼 수 있습니다. 본문에는 설명을 위해 일부만 발췌했지만, 거기엔 전체 코드와 자신의 프로젝트에 적용하는 방법까지 정리해뒀습니다.

게시판에 기능을 붙이는 작업은 여기까지입니다. 다음 장에서는 게시판과는 독립적인 AI 챗봇을 만들어보겠습니다.
