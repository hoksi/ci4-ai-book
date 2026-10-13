# 4장. 모델과 DB

## 이번 장에서 만드는 것

지금까지는 게시글 데이터가 전혀 저장되지 않았습니다. 이번 장에서 `posts` 테이블을 설계하고, Model을 통해 실제로 읽고 쓰도록 만듭니다.

## 마이그레이션: 테이블을 코드로 관리하기

phpMyAdmin 같은 도구로 테이블을 직접 만들면, 그 구조가 어디에도 기록되지 않습니다. 팀원이 늘어나거나 서버를 옮길 때 "테이블이 정확히 어떻게 생겼는지" 다시 알아내야 합니다. **마이그레이션**은 테이블 구조 자체를 코드(버전 관리되는 PHP 클래스)로 남겨두는 방법입니다.

```bash
php spark make:migration CreatePostsTable
```

생성된 파일에 테이블 구조를 정의합니다.

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePostsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INTEGER',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'content'    => ['type' => 'TEXT'],
            'author'     => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => '익명'],
            'views'      => ['type' => 'INTEGER', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('posts');
    }

    public function down(): void
    {
        $this->forge->dropTable('posts');
    }
}
```

`up()`은 "적용할 때" 할 일, `down()`은 "되돌릴 때" 할 일입니다. `deleted_at` 컬럼을 미리 넣어둔 건 소프트 삭제(실제로 행을 지우지 않고 삭제 시각만 기록)를 쓰기 위해서입니다 — Model 쪽에서 바로 활용합니다.

```bash
php spark migrate
```

이 명령 한 번으로 테이블이 실제로 생성됩니다. 13장에서 다루겠지만, `php spark`가 막힌 호스팅에서는 이 과정을 로컬에서 대신 수행해야 합니다.

## Model: 테이블 하나를 다루는 창구

CI4의 `Model` 클래스를 상속하면, 테이블 하나에 대한 CRUD와 검증 규칙을 한곳에 모아둘 수 있습니다.

```php
<?php

namespace App\Models;

use CodeIgniter\Model;

class PostModel extends Model
{
    protected $table          = 'posts';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = true;

    protected $allowedFields = ['title', 'content', 'author', 'views'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    public function incrementViews(int $id): void
    {
        $this->set('views', 'views + 1', false)->where('id', $id)->update();
    }
}
```

몇 가지 중요한 설정을 짚고 넘어갑니다.

- **`$allowedFields`**: 여기 적힌 필드만 `insert()`/`update()`로 쓸 수 있습니다. 사용자가 폼에 `id`나 `views` 같은 필드를 몰래 추가해서 보내도, 목록에 없으면 무시됩니다. **Mass Assignment 공격을 막는 핵심 안전장치**입니다.
- **`$useTimestamps`**: true로 켜두면 `insert()` 시 `created_at`, `update()` 시 `updated_at`을 자동으로 채워줍니다.
- **`$useSoftDeletes`**: true면 `delete()`를 호출해도 실제로 행을 지우지 않고 `deleted_at`에 시각만 기록합니다. 이후 일반 조회(`find()`, `findAll()`)에서는 자동으로 제외됩니다. "실수로 지운 글 복구하기"가 훨씬 쉬워집니다.

`incrementViews()`처럼 이 테이블에만 해당하는 로직은 Model에 메서드로 추가해두면, 컨트롤러 코드가 훨씬 깔끔해집니다.

## 컨트롤러에서 Query Builder 쓰기

2장에서 만든 뼈대에 Model을 연결합니다.

```php
class Board extends BaseController
{
    protected PostModel $model;

    public function __construct()
    {
        $this->model = new PostModel();
    }

    public function index(): string
    {
        $posts = $this->model->orderBy('created_at', 'DESC')->paginate(5, 'default');

        return view('board/index', [
            'title' => '게시판',
            'posts' => $posts,
            'pager' => $this->model->pager,
        ]);
    }

    public function store()
    {
        $this->model->insert([
            'title'   => $this->request->getPost('title'),
            'content' => $this->request->getPost('content'),
            'author'  => $this->request->getPost('author'),
        ]);

        return redirect()->to(base_url('board'));
    }
}
```

`orderBy()`, `paginate()`처럼 체이닝되는 메서드들이 바로 **Query Builder**입니다. SQL을 직접 쓰지 않아도 대부분의 쿼리를 조립할 수 있습니다. `paginate(5, 'default')`는 "페이지당 5개씩, `default` 그룹으로 페이지네이션"이라는 뜻이고, 뷰에서 `$pager`로 페이지 번호 UI를 그릴 수 있습니다.

## 시더: 샘플 데이터 채우기

빈 게시판으로 매번 테스트하기는 불편합니다. **시더(Seeder)**로 샘플 데이터를 한 번에 밀어 넣을 수 있습니다.

```bash
php spark make:seeder PostSeeder
```

```php
<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PostSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->db->table('posts')->insert([
                'title'      => "샘플 게시글 {$i}",
                'content'    => "이것은 {$i}번째 샘플 내용입니다.",
                'author'     => '테스터',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
```

```bash
php spark db:seed PostSeeder
```

## 체크리스트

- [ ] `php spark migrate`로 `posts` 테이블이 생성되었는가 (`php spark migrate:status`로 확인)
- [ ] `$allowedFields`에 없는 필드를 insert해도 무시되는지 직접 테스트해봤는가
- [ ] 게시글을 삭제한 뒤 DB 파일을 직접 열어봐서, 행이 지워진 게 아니라 `deleted_at`만 채워졌는지 확인했는가

다음 장에서는 사용자가 빈 제목이나 짧은 내용으로 글을 등록하지 못하게, 유효성 검사를 붙여보겠습니다.
