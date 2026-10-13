# 12장. 마크다운 응답 안전하게 렌더링하기

## 이번 장에서 만드는 것

LLM은 답변을 거의 항상 마크다운으로 씁니다. `**중요한 부분**`, 번호 매기기 목록, ` ```php ` 코드블록 같은 걸 즐겨 씁니다. 이걸 그대로 화면에 띄우면 사용자는 별표와 백틱이 잔뜩 섞인 날것의 텍스트를 보게 됩니다. 이번 장에서는 AI 답변을 **안전하게** HTML로 바꿔서 굵게/목록/코드블록이 실제로 그렇게 보이도록 만듭니다.

## 왜 "안전하게"가 핵심인가

AI 응답은 결국 **외부에서 온, 신뢰할 수 없는 문자열**입니다. 사용자의 질문에 악의적인 내용을 섞으면(프롬프트 인젝션), 모델이 그걸 그대로 되받아 답변에 포함시킬 가능성을 배제할 수 없습니다. 만약 답변 문자열을 검증 없이 `innerHTML`에 그대로 꽂아 넣으면 XSS(Cross-Site Scripting) 공격 경로가 열립니다.

그래서 원칙은 하나입니다 — **먼저 이스케이프하고, 그 다음에 마크다운 문법만 골라서 안전한 태그로 치환한다.** 절대 반대 순서로 하면 안 됩니다. 이스케이프를 나중에 하면, 치환 과정에서 만들어진 `<strong>` 같은 안전한 태그까지 전부 깨져버립니다.

## 1단계: 무조건 먼저 이스케이프

```js
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
```

AI 답변에 `<script>alert(1)</script>`가 섞여 있었다면, 이 시점에 이미 `&lt;script&gt;alert(1)&lt;/script&gt;`로 완전히 무력화됩니다. 이후의 모든 처리는 **이미 안전해진 문자열 위에서** 정규식으로 패턴만 찾아 바꾸는 작업입니다.

## 2단계: 코드 블록은 따로 빼둔다 (중요)

여기서 실제로 겪은 버그 하나를 소개합니다. 이스케이프 다음에 곧바로 `**굵게**` → `<strong>`, `*기울임*` → `<em>` 치환을 해버리면, **코드 블록 안에 있는 `*` 문자까지 전부 걸려듭니다.** 예를 들어 정규식 코드 ` preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $str) `가 섞인 답변을 그대로 치환하면:

```
preg_match('/^(?=.<em>[A-Z])(?=.</em>\d).{8,}$/', $str)
```

이렇게 코드가 깨집니다. `.* ` 안의 두 `*`가 기울임 마커로 오인된 겁니다.

해결 방법은 "코드(펜스/인라인) 부분을 먼저 토큰으로 빼뒀다가, 굵게/기울임 치환이 끝난 뒤 마지막에 복원"하는 것입니다.

```js
function renderMarkdown(raw) {
    let text = escHtml(raw);
    const blocks = [];

    const stash = (html) => {
        const token = '\u0002' + blocks.length + '\u0003';
        blocks.push(html);
        return token;
    };

    // 펜스 코드 블록(```)을 먼저 토큰으로 격리
    text = text.replace(/```([\s\S]*?)```/g, (_, code) => {
        const body = code.trim().replace(/^[a-zA-Z0-9_+-]*\n/, ''); // 언어 태그 제거
        return stash(`<pre class="md-code-block"><code>${body}</code></pre>`);
    });

    // 인라인 코드(`)도 토큰으로 격리
    text = text.replace(/`([^`\n]+)`/g, (_, code) => stash(`<code>${code}</code>`));

    // 이제 남은 일반 텍스트에만 굵게/기울임 적용 — 코드 속 *는 이미 안전하게 빠져 있다
    text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>');

    // ... 줄 단위 목록 처리 (3단계) ...

    // 맨 마지막에 토큰을 실제 코드 HTML로 복원
    return html.replace(/\u0002(\d+)\u0003/g, (_, i) => blocks[Number(i)]);
}
```

제어 문자(`\u0002`, `\u0003`)로 토큰을 감싸는 이유는, 이런 조합이 AI 응답 텍스트에 실제로 등장할 가능성이 거의 없기 때문입니다. 평범한 문자(예: `[CODE0]`)를 토큰으로 쓰면, 답변 본문에 우연히 똑같은 문자열이 들어있을 때 충돌할 수 있습니다.

## 3단계: 줄 단위로 목록과 문단을 처리한다

번호 매기기(`1. `)와 불릿(`- `)은 한 줄씩 훑으면서 같은 종류가 연속되면 하나의 `<ol>`/`<ul>`로 묶습니다.

```js
const lines = text.split('\n');
let html = '', listBuffer = [], listType = null, ordinal = 1;

const flushList = () => {
    if (listBuffer.length) {
        const startAttr = listType === 'ol' ? ` start="${ordinal}"` : '';
        html += `<${listType} class="md-list"${startAttr}>` +
                listBuffer.map(li => `<li>${li}</li>`).join('') + `</${listType}>`;
        if (listType === 'ol') ordinal += listBuffer.length;
        listBuffer = [];
        listType = null;
    }
};

for (const line of lines) {
    const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
    const bulleted = line.match(/^\s*[-•]\s+(.*)$/);
    if (numbered) {
        if (listType !== 'ol') { flushList(); listType = 'ol'; }
        listBuffer.push(numbered[1]);
    } else if (bulleted) {
        if (listType !== 'ul') { flushList(); listType = 'ul'; }
        listBuffer.push(bulleted[1]);
    } else {
        flushList();
        html += line.trim() === '' ? '<br>' : line + '<br>';
    }
}
flushList();
```

여기서도 실제로 겪은 버그가 하나 있습니다. AI가 "1. 설명 → 코드블록 → 2. 설명 → 코드블록" 식으로 답변하면, 코드블록이 나올 때마다 목록이 끊기면서 매번 새 `<ol>`이 시작되어 **번호가 1, 1, 1, 1로 반복**됩니다. 이를 막기 위해 `ordinal` 변수로 번호를 함수 바깥에서 누적시키고, 목록을 다시 열 때 `start="${ordinal}"` 속성을 붙여 번호가 이어지게 했습니다.

## 4단계: 실제 AI 응답으로 검증하기

"유효성 검사 커스텀 규칙은 어떻게 만드나요?"라고 실제로 물어서 받은 답변 — 4단계 번호 목록 사이사이에 PHP 코드블록이 4개 섞인 복잡한 응답 — 으로 테스트한 결과입니다.

- 번호가 1 → 2 → 3 → 4로 올바르게 이어짐
- 코드블록 안의 `preg_match('/^(?=.*[A-Z])...`가 멀쩡하게 보존됨 (기울임으로 안 깨짐)
- 질문에 일부러 넣은 `<script>alert(1)</script>`는 화면에 글자 그대로 `&lt;script&gt;...`로 표시되고 실행되지 않음

이렇게 실제 데이터로 검증하는 습관은 특히 정규식 기반 파서에서 중요합니다. 머릿속으로 짠 로직이 실제 LLM이 뱉어내는 들쭉날쭉한 형식과는 꼭 한두 군데씩 어긋나기 때문입니다.

## 한계

- 이건 완전한 마크다운 파서가 아닙니다. 테이블, 중첩 목록, 인용문(`>`) 같은 건 처리하지 않습니다. AI 답변 특성상 굵게/목록/코드블록 정도만 커버해도 실용적으로 충분하지만, 더 정교한 렌더링이 필요하면 `marked.js`(마크다운 렌더러) + `DOMPurify`(위험한 HTML을 걸러내는 XSS 방어 라이브러리) 조합을 고려하세요.
- 직접 짠 파서는 테스트를 꼼꼼히 하지 않으면 이번 장에서 겪은 것처럼 "코드 안의 특수문자가 서식으로 오인되는" 종류의 버그가 계속 나올 수 있습니다. Node.js 같은 걸로 렌더링 함수만 따로 떼어내 실제 AI 응답 샘플들을 모아 회귀 테스트하는 걸 권장합니다.
