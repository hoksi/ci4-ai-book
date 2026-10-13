# 2장. 라우팅과 컨트롤러

## 이번 장에서 만드는 것

AI 게시판의 뼈대 — 목록 보기, 글쓰기 폼, 저장 — 을 라우트와 컨트롤러만으로 먼저 만듭니다. DB 연동(4장)과 유효성 검사(5장), 스팸 필터(7장)는 뒤에서 하나씩 붙여나갈 겁니다. 지금은 "요청이 들어오면 어느 코드가 실행되는가"라는 흐름만 잡습니다.

## 라우트: 요청을 컨트롤러로 연결하기

`app/Config/Routes.php`에 URL과 컨트롤러 메서드를 매핑합니다.

```php
$routes->get('board', 'Board::index');
```

`board` 경로로 GET 요청이 오면 `Board` 컨트롤러의 `index()` 메서드를 실행하라는 뜻입니다. 관련된 라우트가 여러 개면 `group()`으로 묶어서 공통 접두사를 줄 수 있습니다.

```php
$routes->group('board', function ($routes) {
    $routes->get('/',              'Board::index');
    $routes->get('create',         'Board::create');
    $routes->post('store',         'Board::store');
    $routes->get('(:num)',         'Board::show/$1');
    $routes->get('(:num)/edit',    'Board::edit/$1');
    $routes->post('(:num)/update', 'Board::update/$1');
    $routes->get('(:num)/delete',  'Board::delete/$1');
});
```

`(:num)`은 숫자만 매칭하는 플레이스홀더입니다. `/board/42`로 요청이 오면 `42`가 `$1`을 거쳐 메서드의 첫 번째 인자로 전달됩니다 — `show(int $id)`처럼 받을 수 있습니다.

`store`, `update`처럼 데이터를 변경하는 라우트는 `get`이 아니라 `post`로 선언했습니다. 폼 제출은 GET이 아니라 POST로 받는 게 원칙입니다(URL에 입력값이 노출되지 않고, 새로고침 시 중복 제출도 방지됩니다).

## 컨트롤러: 뼈대만 먼저

```php
<?php

namespace App\Controllers;

class Board extends BaseController
{
    public function index(): string
    {
        return view('board/index', ['title' => '게시판']);
    }

    public function create(): string
    {
        return view('board/create', ['title' => '게시글 작성']);
    }

    public function store()
    {
        $title   = $this->request->getPost('title');
        $content = $this->request->getPost('content');

        // TODO: DB 저장 — 4장에서 Model을 붙이면서 채웁니다.

        return redirect()->to(base_url('board'));
    }
}
```

지금은 `$posts`를 하드코딩된 빈 배열로 두거나 아예 넘기지 않아도 됩니다. 뷰 템플릿(3장)과 DB 연동(4장)을 순서대로 붙여나가면서 이 뼈대가 점점 채워집니다.

## 왜 처음부터 전체 기능을 한 번에 안 만드는가

실전 코드(`app/Controllers/Examples/Board.php`)를 미리 보면 쿨다운, 중복 글 감지, 읽기 전용 모드, 스팸 검사까지 들어 있어서 훨씬 복잡합니다. 처음부터 그 코드를 통째로 베끼면 "어디까지가 라우팅/컨트롤러의 기본기고 어디부터가 부가 기능인지" 구분이 안 됩니다.

이 책은 의도적으로 **얇은 뼈대 → 장마다 한 겹씩 추가**하는 순서로 진행합니다. 4장에서 DB를 붙이고, 5장에서 검증을 넣고, 7장에서 스팸 필터를 끼워 넣으면, 여러분이 지금 막 만든 이 단순한 컨트롤러가 최종적으로 실전 코드와 거의 같은 모양이 되어 있을 겁니다.

## 체크리스트

- [ ] `board`, `board/create`에 GET으로 접속하면 각각 다른 뷰가 뜨는가
- [ ] `store`를 GET으로 접속했을 때 404(Not Found)가 뜨는가 — POST로만 등록했다면 정상입니다
- [ ] `board/42`처럼 숫자 세그먼트를 넣었을 때 `show(int $id)`가 그 값을 받는가 (`var_dump($id)`로 확인)

다음 장에서는 이 컨트롤러들이 반환하는 뷰를 레이아웃으로 묶어보겠습니다.
