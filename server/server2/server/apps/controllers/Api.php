<?php
namespace App\Controller;
use App;
use Swoole;
use \Michelf;

require_once MARKDOWN_LIB_DIR . '/php-markdown/Michelf/Markdown.php';
require_once MARKDOWN_LIB_DIR . '/php-markdown/Michelf/MarkdownExtra.php';
require_once MARKDOWN_LIB_DIR . '/Content.php';

class Api extends Swoole\Controller
{
    const AVATAR_URL = 'http://group.swoole.com/uploads/avatar/';
    const NO_AVATAR = 'http://group.swoole.com/static/common/';

    static function fillAvatarUrl(&$array, $userinfo)
    {
        if (empty($userinfo['avatar_file']))
        {
            $array['avatar_mini'] = self::NO_AVATAR.'avatar-min-img.jpg';
            $array['avatar_normal'] = self::NO_AVATAR.'avatar-mid-img.jpg';
            $array['avatar_large'] = self::NO_AVATAR.'avatar-max-img.jpg';
        }
        else
        {
            $array['avatar_mini'] = self::AVATAR_URL.$userinfo['avatar_file'];
            $array['avatar_normal'] = self::AVATAR_URL . str_replace('_min.', '_mid.', $userinfo['avatar_file']);
            $array['avatar_large'] = self::AVATAR_URL . str_replace('_min.', '_max.', $userinfo['avatar_file']);
        }
    }

    static function parseMarkdown($text)
    {
        //GitHub Code Parse
        $text = str_replace('```', '~~~', $text);
        $parser = new Michelf\MarkdownExtra;
        $parser->fn_id_prefix = "post22-";
        $parser->code_attr_on_pre = false;
        $parser->tab_width = 4;
        return $parser->transform($text);
    }

    function topic()
    {
        $tpl = array(
            'id' => 0,
            'title' => '',
            'url' => 'http://www.v2ex.com/t/188087',
            'content' => '',
            'content_rendered' => '',
            'replies' => 0,
            'created' => 0,
            'last_modified' => 0,
            'last_touched' => 1430627831,
            'member' =>
                array(
                    'id' => 0,
                    'username' => '',
                    'tagline' => '',
                    'avatar_mini' => '',
                    'avatar_normal' => '',
                    'avatar_large' => '',
                ),
            'node' =>
                array(
                    'id' => 1,
                    'name' => 'create',
                    'title' => '分享创造',
                    'title_alternative' => 'Create',
                    'url' => 'http://www.v2ex.com/go/create',
                    'topics' => 3800,
                    'avatar_mini' => '//cdn.v2ex.co/navatar/70ef/df2e/17_mini.png?m=1430065455',
                    'avatar_normal' => '//cdn.v2ex.co/navatar/70ef/df2e/17_normal.png?m=1430065455',
                    'avatar_large' => '//cdn.v2ex.co/navatar/70ef/df2e/17_large.png?m=1430065455',
                ),
        );

        $gets = array('order' => 'question_id desc',
            'pagesize' => 20);
        $gets['page'] = empty($_GET['page']) ? 1 : intval($_GET['page']);

        $_user = table('aws_users');
        $_user->primary = 'uid';

        if (!empty($_GET['category']))
        {
            $gets['category_id'] = intval($_GET['category']);
        }
        if (!empty($_GET['username']))
        {
            $user = $_user->get($_GET['username'], 'user_name');
            $gets['published_uid'] = $user['uid'];
        }

        $pager = null;
        $list = table('aws_question')->gets($gets, $pager);

        $_uids = array();
        $_categorys = array();
        foreach($list as $li)
        {
            $_uids[$li['published_uid']] = true;
            $_categorys[$li['category_id']] = true;
        }

        if (!empty($_uids))
        {
            $users = $_user->getMap(['in' => array('uid', implode(',', array_keys($_uids)))]);
        }
        else
        {
            $users = array();
        }

        if (!empty($_uids))
        {
            $categorys = table('aws_category')->getMap(['in' => array('id', implode(',', array_keys($_categorys)))]);
        }
        else
        {
            $categorys = array();
        }

        $result = array();
        foreach($list as $li)
        {
            Swoole\Filter::safe($li['question_content']);
            $tpl['id'] = $li['question_id'];
            $tpl['title'] = $li['question_content'];
            $tpl['content'] = $li['question_detail'];
            $tpl['created'] = $li['add_time'];
            $tpl['last_modified'] = $li['update_time'];
            $tpl['replies'] = $li['answer_count'];

            //用户信息
            $uid = $li['published_uid'];
            $tpl['member']['id'] = $uid;
            $tpl['member']['username'] = $users[$uid]['user_name'];

            $_category_id = $li['category_id'];
            $tpl['node']['id'] = $_category_id;
            $tpl['node']['title_alternative'] = $tpl['node']['title'] = $tpl['node']['name'] = $categorys[$_category_id]['title'];
            $tpl['node']['name'] = $categorys[$_category_id]['title'];
            //头像
            self::fillAvatarUrl($tpl['member'], $users[$uid]);
            $tpl['content_rendered'] = self::parseMarkdown($li['question_detail']);
            $result[] = $tpl;
        }
        echo json_encode($result);
    }

    function category()
    {
        $tpl = array (
            'id' => 0,
            'name' => '',
            'url' => 'http://www.v2ex.com/go/babel',
            'title' => '',
            'title_alternative' => '',
            'topics' => 0,
            'header' => '',
            'footer' => '',
            'created' => 0,
        );

        $counts = $this->db->query("SELECT count(*) as c, category_id FROM `aws_question` WHERE 1 group by category_id")
            ->fetchall();
        $topic_num = [];
        foreach($counts as $c)
        {
            $topic_num[$c['category_id']] = $c['c'];
        }

        $list = table('aws_category')->gets(array('limit' => 100, 'order' => 'id asc'));
        $result = [];
        foreach ($list as $li)
        {
            $tpl['id'] = $li['id'];
            $tpl['title_alternative'] = $tpl['title'] = $tpl['name'] = $li['title'];
            $tpl['created'] = 0;
            if (isset($topic_num[$li['id']]))
            {
                $tpl['topics'] = $topic_num[$li['id']];
            }
            else
            {
                $tpl['topics'] = 0;
            }
            $result[] = $tpl;
        }
        echo json_encode($result);
    }

    function reply()
    {
        if (empty($_GET['topic_id']))
        {
            no_reply:
            return json_encode([]);
        }

        $_reply = table('aws_answer');
        $list = $_reply->gets(['question_id' => intval($_GET['topic_id']), 'order' => 'answer_id asc']);

        if (empty($list))
        {
            goto no_reply;
        }
        $_uids = array();
        foreach($list as $li)
        {
            $_uids[$li['uid']] = 1;
        }

        $_user = table('aws_users');
        $_user->primary = 'uid';
        $users = $_user->getMap(['in' => array('uid', implode(',', array_keys($_uids)))]);

        $result = array();
        foreach($list as $li)
        {
            $tpl['id'] = $li['answer_id'];
            $tpl['content'] = $li['answer_content'];
            $tpl['content_rendered'] = self::parseMarkdown($li['answer_content']);

            $tpl['created'] = $li['add_time'];
            $tpl['last_modified'] = $li['add_time'];
            $tpl['thanks'] = $li['thanks_count'];

            //用户信息
            $uid = $li['uid'];
            $tpl['member']['id'] = $uid;
            $tpl['member']['username'] = $users[$uid]['user_name'];
            $tpl['member']['tagline'] = '';
            if (empty($users[$uid]['avatar_file']))
            {
                $tpl['member']['avatar_mini'] = self::NO_AVATAR.'avatar-min-img.jpg';
                $tpl['member']['avatar_normal'] = self::NO_AVATAR.'avatar-mid-img.jpg';
                $tpl['member']['avatar_large'] = self::NO_AVATAR.'avatar-max-img.jpg';
            }
            else
            {
                $tpl['member']['avatar_mini'] = self::AVATAR_URL.$users[$uid]['avatar_file'];
                $tpl['member']['avatar_normal'] = self::AVATAR_URL . str_replace('_min.', '_mid.', $users[$uid]['avatar_file']);
                $tpl['member']['avatar_large'] = self::AVATAR_URL . str_replace('_min.', '_max.', $users[$uid]['avatar_file']);
            }
            $result[] = $tpl;
        }
        return json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    /**
     * 登录
     * @return string
     */
    function login()
    {
        if (empty($_POST['password']) or empty($_POST['username']))
        {
            $this->http->status(403);
            return "access deny\n";
        }

        $this->session->start();

        $_user = table('aws_users');
        $_user->primary = 'uid';

        $userinfo = $_user->get(trim($_POST['username']), 'user_name');
        if ($userinfo->exist())
        {
            if (self::check_password($userinfo, $_POST['password']) === false)
            {
                goto error_user;
            }
            else
            {
                $_SESSION['user'] = $userinfo->get();
                return $this->json();
            }
        }
        else
        {
            error_user:
            return $this->json('', 403, "错误的用户名或密码");
        }
    }

    /**
     * 退出登录
     * @return string
     */
    function logout()
    {
        $this->session->start();
        $_SESSION = array();
        return $this->json();
    }

    function profile()
    {
        $this->session->start();
        if (empty($_SESSION['user']))
        {
            return $this->json('', 403, "需要登录");
        }

        $user = $_SESSION['user'];
        $categorys = table('aws_category')->gets(array('limit' => 100, 'order' => 'id asc'));
        $collections = [];
        foreach($categorys as $c)
        {
            $collections[] = $c['title'];
        }

        $profile = ['username' => $user['user_name'],
            'collections' => $collections,
        ];
        self::fillAvatarUrl($profile, $user);
        return $this->json($profile);
    }

    function member()
    {
        $user = table('aws_users')->get(trim($_GET['username']), 'user_name');
        $profile =['username' => $user['user_name'],
                   'collections' => $collections,];

        return $this->json($profile);

        $extra = table('aws_users_attrib')->get($user['uid'], 'uid');
        if ($extra->exist())
        {
            $profile['signature'] = $extra['signature'];
        }

    }

    static function check_password($userinfo, $post_password)
    {
        $salt = $userinfo['salt'];
        if (strlen($post_password) == 32)
        {
            $md5 = md5($post_password . $salt);
        }
        else
        {
            $md5 = md5(md5($post_password) . $salt);
        }
        return $md5 == $userinfo['password'];
    }

    function post_comment()
    {
        if (empty($_POST['content']) or empty($_POST['topic_id']))
        {
            $this->http->status(403);
            return "access deny\n";
        }
        $this->session->start();
        if (empty($_SESSION['user']))
        {
            return $this->json('', 403, "需要登录");
        }

        $topic_id = intval($_POST['topic_id']);
        $_table = table('aws_question');
        $_table->primary = 'question_id';
        $topic = $_table->get($topic_id);

        if ($topic->exist() === false)
        {
            return $this->json('', 404, "主题不存在");
        }

        //计数
        $topic->answer_count += 1;
        $topic->answer_users += 1;
        $topic->save();

        $user = $_SESSION['user'];
        $put['question_id'] = $topic_id;
        $put['uid'] = $user['uid'];
        $put['add_time'] = time();
        $put['answer_content'] = trim($_POST['content']);
        $put['ip'] = ip2long(Swoole\Client::getIP());
        $put['category_id'] = $topic['category_id'];
        $id = table('aws_answer')->put($put);

        if ($id)
        {
            return $this->json(['commit_id' => $id]);
        }
        else
        {
            return $this->json('', 500, "操作失败，请稍后重试");
        }
    }

    function post_topic()
    {
        if (empty($_POST['content']) or empty($_POST['title']) or empty($_POST['category_id']))
        {
            $this->http->status(403);
            return "access deny\n";
        }
        $this->session->start();
        if (empty($_SESSION['user']))
        {
            return $this->json('', 403, "需要登录");
        }

        $user = $_SESSION['user'];
        $put['question_content'] = trim($_POST['title']);
        $put['question_detail'] = trim($_POST['content']);
        $put['published_uid'] = $user['uid'];
        $put['update_time'] = $put['add_time'] = time();
        $put['ip'] = ip2long(Swoole\Client::getIP());
        $put['category_id'] = intval($_POST['category_id']);
        $id = table('aws_question')->put($put);

        if ($id)
        {
            return $this->json(['topic_id' => $id]);
        }
        else
        {
            return $this->json('', 500, "操作失败，请稍后重试");
        }
    }

    function new_message()
    {
        $this->session->start();
        if (empty($_SESSION['user']))
        {
            return $this->json('', 403, "需要登录");
        }
        $user = $_SESSION['user'];
        $gets['to_uid'] = $user['uid'];
        $gets['isread'] = 0;
        $gets['select'] = "question_id as topic_id, title, message as content, time, uid";
        $message = table('aws_question_comments')->gets($gets);
        return $this->json($message);
    }
}
