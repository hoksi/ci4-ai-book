# 5장. 폼과 유효성 검사

## 이번 장에서 만드는 것

4장까지는 사용자가 뭘 입력하든 그대로 DB에 저장됐습니다. 빈 제목, 한 글자짜리 내용도 전부 통과됩니다. 이번 장에서 "제목은 2자 이상", "내용은 10자 이상" 같은 규칙을 걸고, 규칙을 어기면 친절한 에러 메시지로 되돌려주도록 만듭니다.

## 기본 규칙

```php
public function store()
{
    $rules = [
        'title'   => 'required|min_length[2]|max_length[200]',
        'content' => 'required|min_length[10]',
        'author'  => 'required|min_length[2]|max_length[100]',
    ];

    if (! $this->validate($rules)) {
        return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
    }

    $this->model->insert([
        'title'   => $this->request->getPost('title'),
        'content' => $this->request->getPost('content'),
        'author'  => $this->request->getPost('author'),
    ]);

    return redirect()->to(base_url('board'));
}
```

`$this->validate($rules)`는 `BaseController`에 이미 포함된 트레이트(`ValidationTrait`)가 제공하는 메서드입니다. 현재 요청의 POST 데이터를 규칙에 맞춰 검사하고, 통과하면 `true`를 돌려줍니다.

실패했을 때는 `withInput()`으로 사용자가 입력했던 값을 그대로 되돌려주는 게 중요합니다 — 에러가 나서 다시 폼을 보여줄 때, 방금 썼던 내용이 다 날아가 있으면 사용자가 짜증을 냅니다.

```php
<!-- app/Views/board/create.php -->
<?php if (session('errors')): ?>
    <ul class="errors">
        <?php foreach (session('errors') as $error): ?>
        <li><?= esc($error) ?></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<form method="post" action="<?= base_url('board/store') ?>">
    <input type="text" name="title" value="<?= esc(old('title')) ?>">
    <textarea name="content"><?= esc(old('content')) ?></textarea>
    <button type="submit">등록</button>
</form>
```

`old('title')`은 `withInput()`으로 돌려받은 이전 입력값을 꺼내는 헬퍼입니다.

## 규칙을 Model에 두면 재사용이 쉬워진다

컨트롤러마다 규칙을 흩어놓으면, 같은 테이블을 다루는 다른 메서드(수정 폼 등)에서 규칙이 미묘하게 달라지는 실수가 생깁니다. Model에 규칙을 선언해두고 가져다 쓰는 패턴을 권장합니다.

```php
class PostModel extends Model
{
    protected $validationRules = [
        'title'   => 'required|min_length[2]|max_length[200]',
        'content' => 'required|min_length[10]',
        'author'  => 'required|min_length[2]|max_length[100]',
    ];

    protected $validationMessages = [
        'title' => [
            'required'   => '제목을 입력해주세요.',
            'min_length' => '제목은 최소 2자 이상이어야 합니다.',
        ],
        'content' => [
            'required'   => '내용을 입력해주세요.',
            'min_length' => '내용은 최소 10자 이상이어야 합니다.',
        ],
    ];
}
```

규칙을 Model에 선언해두면, `insert()`/`update()` 호출 시 **자동으로** 검증이 실행됩니다. 실패하면 `insert()`가 `false`를 반환하고, `$model->errors()`로 에러 메시지를 꺼낼 수 있습니다.

```php
if (! $this->model->insert($data)) {
    return redirect()->back()->withInput()->with('errors', $this->model->errors());
}
```

## 커스텀 에러 메시지와 커스텀 규칙

기본 메시지("The title field is required")는 영어이고 딱딱합니다. 필드별로 메시지를 직접 지정할 수 있습니다.

```php
$rules = [
    'username' => [
        'label' => '사용자명',
        'rules' => 'required|alpha_numeric|min_length[4]|max_length[16]',
        'errors' => [
            'required'      => '{field}은(는) 필수 입력 항목입니다.',
            'alpha_numeric' => '{field}은(는) 영문자와 숫자만 사용 가능합니다.',
            'min_length'    => '{field}은(는) 최소 {param}자 이상이어야 합니다.',
        ],
    ],
];
```

`{field}`, `{param}`은 CI4가 자동으로 치환해주는 플레이스홀더입니다. `label`을 지정해두면 `{field}`가 필드명(`username`) 대신 한글 레이블("사용자명")로 바뀝니다.

내장 규칙만으로 부족할 땐 `regex_match`로 직접 패턴을 걸 수도 있습니다.

```php
'password' => [
    'label' => '비밀번호',
    'rules' => 'required|min_length[8]|regex_match[/^(?=.*[A-Z])(?=.*\d).+$/]',
    'errors' => [
        'regex_match' => '{field}은(는) 대문자와 숫자를 각각 하나 이상 포함해야 합니다.',
    ],
],
'password_confirm' => [
    'label' => '비밀번호 확인',
    'rules' => 'required|matches[password]',
    'errors' => ['matches' => '비밀번호가 일치하지 않습니다.'],
],
```

`matches[password]`처럼 다른 필드의 값과 비교하는 규칙도 기본 제공됩니다. 비밀번호 확인 같은 흔한 패턴에 바로 쓸 수 있습니다.

## 체크리스트

- [ ] 제목을 비워두고 제출하면 에러 메시지가 뜨는가
- [ ] 에러가 났을 때 폼에 입력했던 값이 그대로 남아있는가 (`old()` 확인)
- [ ] Model에 규칙을 선언한 경우, `insert()` 실패 시 `$model->errors()`로 에러를 꺼낼 수 있는가

여기까지 1부(기초 다지기)를 마쳤습니다. 2부에서는 이 게시판에 AI 기능을 하나씩 붙여나갑니다 — 바로 다음 장은 외부 API(Groq)에 첫 요청을 보내는 것부터 시작합니다.
