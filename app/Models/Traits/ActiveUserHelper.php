<?php
namespace App\Models\Traits;

use App\Models\Reply;
use App\Models\Topic;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use DB;

trait ActiveUserHelper
{
    protected $users = [];
    //配置权重和范围
    protected $topic_weight = 4;
    protected $reply_weight =1;
    protected $pass_days = 7;
    protected $user_count = 6;

    //配置缓存
    protected $cache_key = 'active_users';
    protected $cache_expires_in_minutes = 60;

    public function getActiveUsers()
    {
        //尝试从缓存中直接取
        return Cache::remember($this->cache_key, $this->cache_expires_in_minutes, function (){
            return $this->calculateActiveUsers();
        });
    }

    public function calculateAndCacheActiveUsers()
    {
        // 取得活跃用户列表
        $active_users = $this->calculateActiveUsers();
        // 并加以缓存
        $this->cacheActiveUsers($active_users);
    }

    public function cacheActiveUsers($active_users)
    {
        Cache::forget($this->cache_key);
        // 将数据放入缓存中
        Cache::put($this->cache_key, $active_users, $this->cache_expire_in_minutes);
    }

    public function calculateActiveUsers()
    {
        $this->calculateTopicScore();
        $this->calculateReplyScore();
        // 数组按照得分排序
        $users = array_sort($this->users, function ($user) {
            return $user['score'];
        });

        $users = array_reverse($users, true);
        $users = array_slice($users, 0, $this->user_count, true);

        // 新建一个空集合
        $active_users = collect();

        foreach ($users as $user_id => $user) {
            // 找寻下是否可以找到用户
            $user = $this->find($user_id);

            // 如果数据库里有该用户的话
            if ($user) {

                // 将此用户实体放入集合的末尾
                $active_users->push($user);
            }
        }
        // 返回数据
        return $active_users;

    }

    public function calculateTopicScore()
    {
        // 从话题数据表里取出限定时间范围（$pass_days）内，有发表过话题的用户
        // 并且同时取出用户此段时间内发布话题的数量
        $topic_users = Topic::query()
            ->select(DB::raw('user_id, count(*) as topic_count'))
            ->where('created_at', '>=', Carbon::now()->subDays($this->pass_days))
            ->groupBy('user_id')
            ->get();

        foreach ($topic_users as $user) {
            $this->users[$user->user_id]['score'] = $user->topic_count * $this->topic_weight;
        }

    }

    public function calculateReplyScore()
    {
        $reply_users = Reply::query()
            ->select(DB::raw('user_id, count(*) as reply_count'))
            ->where('created_at', '>=', Carbon::now()->subDays($this->pass_days))
            ->groupBy('user_id')
            ->get();

        foreach ($reply_users as $user) {
            if (!isset($this->users[$user->user_id])) {
                $this->users[$user->user_id]['score'] = 0;
            }
            $this->users[$user->user_id]['score'] += $user->reply_count * $this->reply_weight;
        }
    }
}