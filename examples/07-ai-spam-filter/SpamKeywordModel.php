<?php

namespace App\Models;

use CodeIgniter\Model;

class SpamKeywordModel extends Model
{
    protected $table      = 'spam_keywords';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = ['keyword', 'frequency', 'active', 'is_builtin'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * 활성 키워드 목록 반환 (캐시 10분)
     *
     * @return string[]
     */
    public function getActiveKeywords(): array
    {
        $cached = cache('spam_keywords_active');
        if ($cached !== null) {
            return $cached;
        }

        $keywords = $this->where('active', 1)
            ->orderBy('frequency', 'DESC')
            ->findColumn('keyword') ?? [];

        cache()->save('spam_keywords_active', $keywords, 600);

        return $keywords;
    }

    /**
     * 키워드 저장 (이미 있으면 frequency +1)
     */
    public function saveOrIncrement(string $keyword): void
    {
        $keyword = mb_strtolower(trim($keyword));
        if (empty($keyword) || mb_strlen($keyword) < 2) {
            return;
        }

        if ($this->where('keyword', $keyword)->countAllResults() > 0) {
            $this->where('keyword', $keyword)
                 ->set('frequency', 'frequency + 1', false)
                 ->update();
        } else {
            $this->insert(['keyword' => $keyword, 'frequency' => 1, 'active' => 1]);
        }

        cache()->delete('spam_keywords_active');
    }

    /**
     * 활성/비활성 토글
     */
    public function toggle(int $id): void
    {
        $row = $this->find($id);
        if ($row) {
            $this->update($id, ['active' => $row['active'] ? 0 : 1]);
            cache()->delete('spam_keywords_active');
        }
    }
}
