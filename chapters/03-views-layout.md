# 3장. 뷰와 레이아웃

## 이번 장에서 만드는 것

2장에서 만든 컨트롤러가 `view('board/index', [...])`를 호출하고 있습니다. 이번 장에서는 그 뷰 파일을 실제로 만들고, 모든 페이지가 공유하는 공통 레이아웃(헤더/네비게이션/푸터)으로 감싸는 법을 다룹니다.

## view() 헬퍼의 기본

```php
return view('board/index', ['title' => '게시판', 'posts' => []]);
```

`app/Views/board/index.php` 파일을 찾아서, 두 번째 인자로 넘긴 배열의 키를 그 파일 안에서 변수로 쓸 수 있게 해줍니다.

```php
<!-- app/Views/board/index.php -->
<h1><?= esc($title) ?></h1>

<?php if (empty($posts)): ?>
    <p>아직 게시글이 없습니다.</p>
<?php else: ?>
    <ul>
        <?php foreach ($posts as $post): ?>
        <li><?= esc($post->title) ?></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
```

`esc()`는 꼭 기억해두세요. 사용자가 입력한 값(제목, 내용)을 화면에 그대로 찍으면 XSS 공격에 노출됩니다. `esc()`는 HTML 특수문자를 이스케이프해서 안전하게 만들어줍니다. (AI가 생성한 텍스트를 다룰 때도 똑같은 원칙이 적용됩니다 — 12장에서 다시 다룹니다.)

## 매 페이지마다 `<html>`, `<head>`, 네비게이션을 반복해서 쓸 건가요?

아닙니다. CI4는 **레이아웃 상속** 패턴을 제공합니다. 공통 틀을 담은 레이아웃 파일 하나를 만들고, 각 페이지 뷰는 "이 레이아웃을 확장해서, content 부분만 채운다"고 선언합니다.

```php
<!-- app/Views/layouts/main.php -->
<!DOCTYPE html>
<html lang="ko">
<head>
    <title><?= esc($title ?? 'AI 게시판') ?></title>
</head>
<body>
    <nav><!-- 공통 네비게이션 --></nav>

    <main>
        <?= $this->renderSection('content') ?>
    </main>

    <?= $this->renderSection('scripts') ?>
</body>
</html>
```

`renderSection('content')`는 "자식 뷰가 `content`라는 이름으로 채워준 내용을 여기에 꽂아 넣어라"는 뜻입니다. 자식 뷰 쪽은 이렇게 씁니다.

```php
<!-- app/Views/board/index.php -->
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<h1><?= esc($title) ?></h1>
<!-- 게시글 목록 -->

<?= $this->endSection() ?>
```

`extend()`로 어떤 레이아웃을 쓸지 선언하고, `section('content')` \~ `endSection()` 사이에 실제 내용을 채웁니다. 이 구조 덕분에 `<nav>`를 한 군데(레이아웃 파일)에서만 관리하면 모든 페이지에 자동으로 반영됩니다.

## 섹션은 여러 개 쓸 수 있다

`content` 외에 `scripts` 섹션도 흔하게 씁니다. 페이지마다 다른 JavaScript가 필요할 때, `<script>` 태그를 `content`와 분리해서 `</body>` 직전에 몰아넣을 수 있습니다.

```php
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<!-- ... -->
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    console.log('이 페이지에서만 실행되는 스크립트');
</script>
<?= $this->endSection() ?>
```

레이아웃 쪽에서 `renderSection('scripts')`를 `renderSection('content')`보다 나중에(보통 `</body>` 바로 앞) 호출하도록 배치하면, 모든 DOM이 그려진 뒤에 스크립트가 실행되어 자연스럽습니다.

## 현재 페이지를 네비게이션에서 강조하기

실전에서는 "지금 보고 있는 메뉴를 하이라이트" 같은 처리도 레이아웃 쪽에 몰아둡니다.

```php
<?php $current = uri_string(); ?>
<a href="<?= base_url('board') ?>" class="<?= str_starts_with($current, 'board') ? 'active' : '' ?>">
    게시판
</a>
```

`uri_string()`은 현재 요청 경로(쿼리스트링 제외)를 돌려주는 헬퍼입니다. 이런 공통 로직을 레이아웃에 한 번만 써두면, 새 페이지를 추가할 때마다 네비게이션 코드를 복붙할 필요가 없어집니다.

## 체크리스트

- [ ] `board/index.php`가 `layouts/main.php`를 extend하고 있는가
- [ ] 레이아웃을 수정했을 때 모든 페이지에 변경사항이 반영되는가
- [ ] 사용자 입력값을 출력하는 모든 곳에 `esc()`를 쓰고 있는가

다음 장에서는 지금 하드코딩되어 있는 `$posts = []`를 실제 DB에서 가져오도록 Model을 붙여보겠습니다.
