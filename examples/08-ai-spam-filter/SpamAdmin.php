<?php

namespace App\Controllers\Examples;

use App\Controllers\BaseController;
use App\Models\PostModel;
use App\Models\SpamKeywordModel;
use App\Services\SpamChecker;

class SpamAdmin extends BaseController
{
    protected SpamKeywordModel $model;
    protected PostModel $postModel;

    public function __construct()
    {
        $this->model     = new SpamKeywordModel();
        $this->postModel = new PostModel();
    }

    public function index(): string
    {
        $keywords    = $this->model->orderBy('is_builtin', 'DESC')->orderBy('frequency', 'DESC')->findAll();
        $reviewPosts = $this->postModel->where('spam_status', 'review')->orderBy('created_at', 'DESC')->findAll();

        return view('examples/spam-admin/index', [
            'title'       => '스팸 키워드 관리',
            'keywords'    => $keywords,
            'reviewPosts' => $reviewPosts,
        ]);
    }

    public function approvePost(int $id)
    {
        $this->postModel->update($id, ['spam_status' => 'approved']);

        return redirect()->to(base_url('examples/spam-admin'))
            ->with('success', '게시글이 승인되었습니다.');
    }

    public function spamPost(int $id)
    {
        $this->postModel->update($id, ['spam_status' => 'spam']);

        return redirect()->to(base_url('examples/spam-admin'))
            ->with('success', '게시글이 스팸으로 처리되었습니다.');
    }

    public function toggle(int $id)
    {
        $this->model->toggle($id);

        return redirect()->to(base_url('examples/spam-admin'))
            ->with('success', '키워드 상태가 변경되었습니다.');
    }

    public function delete(int $id)
    {
        $keyword = $this->model->find($id);
        if ($keyword && $keyword['is_builtin']) {
            return redirect()->to(base_url('examples/spam-admin'))
                ->with('error', '내장 키워드는 삭제할 수 없습니다. 비활성화를 사용하세요.');
        }

        $this->model->delete($id);
        cache()->delete('spam_keywords_active');

        return redirect()->to(base_url('examples/spam-admin'))
            ->with('success', '키워드가 삭제되었습니다.');
    }

    public function store()
    {
        $keyword = trim((string) $this->request->getPost('keyword'));

        if (mb_strlen($keyword) < 2) {
            return redirect()->back()->with('error', '키워드는 2자 이상 입력해주세요.');
        }

        $this->model->saveOrIncrement($keyword);

        return redirect()->to(base_url('examples/spam-admin'))
            ->with('success', "'{$keyword}' 키워드가 추가되었습니다.");
    }

    public function test()
    {
        $title   = (string) $this->request->getPost('title');
        $content = (string) $this->request->getPost('content');

        if (empty($title) || empty($content)) {
            return $this->response->setJSON(['error' => '제목과 내용을 입력해주세요.']);
        }

        $result = (new SpamChecker())->check($title, $content, $this->request->getIPAddress());

        return $this->response->setJSON($result);
    }

}
