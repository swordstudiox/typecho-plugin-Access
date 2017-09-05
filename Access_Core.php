<?php
if (!defined('__ACCESS_PLUGIN_ROOT__')) {
    throw new Exception('Boostrap file not found');
}

class Access_Core
{
    protected $db;
    protected $request;
    protected $response;
    
    public $ua;
    public $config;
    public $action;
    public $title;
    public $logs = array();
    public $overview = array();
    public $referer = array();

    /**
     * 构造函数，根据不同类型的请求，计算不同的数据并渲染输出
     *
     * @access public
     * @return void
     */
    public function __construct()
    {
        # Load language pack
        if (Typecho_I18n::getLang() != 'zh_CN') {
            $file = __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ .
                    '/Access/lang/' . Typecho_I18n::getLang() . '.mo';
            file_exists($file) && Typecho_I18n::addLang($file);
        }
        # Init variables
        $this->db       = Typecho_Db::get();
        $this->config   = Typecho_Widget::widget('Widget_Options')->plugin('Access');
        $this->request  = Typecho_Request::getInstance();
        $this->response = Typecho_Response::getInstance();
        if ($this->config->pageSize == null || $this->config->isDrop == null) {
            throw new Typecho_Plugin_Exception(_t('请先设置插件！'));
        }
        $this->ua = new Access_UA($this->request->getAgent());
        switch ($this->request->get('action')) {
            case 'overview':
                $this->action = 'overview';
                $this->title = _t('访问概览');
                $this->parseOverview();
                $this->parseReferer();
                break;
            case 'logs':
            default:
                $this->action = 'logs';
                $this->title = _t('访问日志');
                $this->parseLogs();
                break;
        }
    }

    /**
     * 生成详细访问日志数据，提供给页面渲染使用
     *
     * @access public
     * @return void
     */
    protected function parseLogs()
    {
        $type = $this->request->get('type', 1);
        $filter = $this->request->get('filter', 'all');
        $pagenum = $this->request->get('page', 1);
        $offset = (max(intval($pagenum), 1) - 1) * $this->config->pageSize;
        $query = $this->db->select()->from('table.access_log')
                    ->order('time', Typecho_Db::SORT_DESC)
                    ->offset($offset)->limit($this->config->pageSize);
        $qcount = $this->db->select('count(1) AS count')->from('table.access_log');
        switch ($type) {
            case 1:
                $query->where('robot = ?', 0);
                $qcount->where('robot = ?', 0);
                break;
            case 2:
                $query->where('robot = ?', 1);
                $qcount->where('robot = ?', 1);
                break;
            default:
                break;
        }
        switch ($filter) {
            case 'ip':
                $ip = $this->request->get('ip', '');
                $ip = bindec(decbin(ip2long($ip)));
                $query->where('ip = ?', $ip);
                $qcount->where('ip = ?', $ip);
                break;
            case 'post':
                $cid = $this->request->get('cid', '');
                $query->where('content_id = ?', $cid);
                $qcount->where('content_id = ?', $cid);
                break;
            case 'path':
                $path = $this->request->get('path', '');
                $query->where('path = ?', $path);
                $qcount->where('path = ?', $path);
                break;
        }
        $list = $this->db->fetchAll($query);
        foreach ($list as &$row) {
            $ua = new Access_UA($row['ua']);
            if ($ua->isRobot()) {
                $name = $ua->getRobotID();
                $version = $ua->getRobotVersion();
            } else {
                $name = $ua->getBrowserName();
                $version = $ua->getBrowserVersion();
            }
            if ($name == '') {
                $row['display_name'] = _t('未知');
            } elseif ($version == '') {
                $row['display_name'] = $name;
            } else {
                $row['display_name'] = $name . ' / ' . $version;
            }
        }
        $this->logs['list'] = $this->htmlEncode($this->urlDecode($list));

        $this->logs['rows'] = $this->db->fetchAll($qcount)[0]['count'];
        
        $page = new Access_Page($this->config->pageSize, $this->logs['rows'], $pagenum, 10, array(
            'panel' => Access_Plugin::$panel,
            'action' => 'logs',
            'type' => $type,
        ));
        $this->logs['page'] = $page->show();

        $this->logs['cidList'] = $this->db->fetchAll($this->db->select('DISTINCT content_id as cid, COUNT(1) as count, table.contents.title')
                ->from('table.access_log')
                ->join('table.contents', 'table.access_log.content_id = table.contents.cid')
                ->where('table.access_log.content_id <> ?', null)
                ->group('table.access_log.content_id')
                ->order('count', Typecho_Db::SORT_DESC));
    }

    /**
     * 生成来源统计数据，提供给页面渲染使用
     *
     * @access public
     * @return void
     */
    protected function parseReferer()
    {
        $this->referer['url'] = $this->db->fetchAll($this->db->select('DISTINCT entrypoint AS value, COUNT(1) as count')
            ->from('table.access_log')->where("entrypoint <> ''")->group('entrypoint')
            ->order('count', Typecho_Db::SORT_DESC)->limit($this->config->pageSize));
        $this->referer['domain'] = $this->db->fetchAll($this->db->select('DISTINCT entrypoint_domain AS value, COUNT(1) as count')
            ->from('table.access_log')->where("entrypoint_domain <> ''")->group('entrypoint_domain')
            ->order('count', Typecho_Db::SORT_DESC)->limit($this->config->pageSize));
        $this->referer = $this->htmlEncode($this->urlDecode($this->referer));
    }

    /**
     * 生成总览数据，提供给页面渲染使用
     *
     * @access public
     * @return void
     */
    protected function parseOverview()
    {
        # 初始化统计数组
        foreach (['ip', 'uv', 'pv'] as $type) {
            foreach (['today', 'yesterday'] as $day) {
                $this->overview[$type][$day]['total'] = 0;
            }
        }
        
        # 分类分时段统计数据
        foreach (['today' => date("Y-m-d"), 'yesterday'=> date("Y-m-d", time() - 24 * 60 * 60)] as $day => $time) {
            for ($i = 0; $i < 24; $i++) {
                $time = date("Y-m-d");
                $start = strtotime(date("{$time} {$i}:00:00"));
                $end   = strtotime(date("{$time} {$i}:59:59"));
                // "SELECT DISTINCT ip FROM {$this->table} {$where} AND `time` BETWEEN {$start} AND {$end}"));
                $this->overview['ip'][$day]['hours'][$i] = intval($this->db->fetchAll($this->db->select('COUNT(1) AS count')
                     ->from('(' . $this->db->select('DISTINCT ip')->from('table.access_log')
                     ->where("time >= {$start} AND time <= {$end}") . ') AS tmp'))[0]['count']);
                $this->overview['ip'][$day]['total'] += $this->overview['ip'][$day]['hours'][$i];
                // "SELECT DISTINCT ip,ua FROM {$this->table} {$where} AND `time` BETWEEN {$start} AND {$end}"));
                $this->overview['uv'][$day]['hours'][$i] = intval($this->db->fetchAll($this->db->select('COUNT(1) AS count')
                     ->from('(' . $this->db->select('DISTINCT ip,ua')->from('table.access_log')
                     ->where("time >= {$start} AND time <= {$end}") . ') AS tmp'))[0]['count']);
                $this->overview['uv'][$day]['total'] += $this->overview['uv'][$day]['hours'][$i];
                // "SELECT ip FROM {$this->table} {$where} AND `time` BETWEEN {$start} AND {$end}"));
                $this->overview['pv'][$day]['hours'][$i] = intval($this->db->fetchAll($this->db->select('COUNT(1) AS count')
                     ->from('table.access_log')->where('time >= ? AND time <= ?', $start, $end))[0]['count']);
                $this->overview['pv'][$day]['total'] += $this->overview['pv'][$day]['hours'][$i];
            }
        }

        # 总统计数据
        // "SELECT DISTINCT ip FROM {$this->table} {$where}"));
        $this->overview['ip']['all']['total'] = $this->db->fetchAll($this->db->select('COUNT(1) AS count')
             ->from('(' . $this->db->select('DISTINCT ip')->from('table.access_log') . ') AS tmp'))[0]['count'];
        // "SELECT DISTINCT ip,ua FROM {$this->table} {$where}"));
        $this->overview['uv']['all']['total'] = $this->db->fetchAll($this->db->select('COUNT(1) AS count')
             ->from('(' . $this->db->select('DISTINCT ip,ua')->from('table.access_log') . ') AS tmp'))[0]['count'];
        // "SELECT ip FROM {$this->table} {$where}"));
        $this->overview['pv']['all']['total'] = $this->db->fetchAll($this->db->select('COUNT(1) AS count')
             ->from('table.access_log'))[0]['count'];

        # 分类型绘制24小时访问图
        $this->overview['chart']['xAxis']['categories'] = json_encode([
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23
        ]);
        foreach (['ip', 'uv', 'pv'] as $type) {
            $this->overview['chart']['series'][$type] = json_encode($this->overview[$type]['today']['hours']);
        }
        $this->overview['chart']['title']['text'] = _t('%s 统计', date("Y-m-d"));
    }

    /**
     * 编码数组中的字符串为 HTML 实体
     * 默认只有数组的值被编码，下标不被编码
     * 如果数据类型是数组，那么它的所有子元素都将被递归编码
     * 只有字符串类型才会被编码
     * @param array $data 将要被编码的数据
     * @param bool $valuesOnly 是否只编码数组数值，如果为 false 那么所有下标和值都将被编码
     * @param string $charset 字符串编码方式，默认为 UTF-8
     * @return array 编码后的数据
     * @see http://www.php.net/manual/en/function.htmlspecialchars.php
     */
    protected function htmlEncode($data, $valuesOnly = true, $charset = 'UTF-8')
    {
        if (is_array($data)) {
            $d = [];
            foreach ($data as $key => $value) {
                if (!$valuesOnly) {
                    $key = $this->htmlEncode($key, $valuesOnly, $charset);
                }
                $d[$key] = $this->htmlEncode($value, $valuesOnly, $charset);
            }
            $data = $d;
        } elseif (is_string($data)) {
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, $charset);
        }
        return $data;
    }

    /**
     * 解析所有 URL 编码过的字符
     * 默认只有数组的值被解码，下标不被解码
     * 如果数据类型是数组，那么它的所有子元素都将被递归解码
     * 只有字符串类型才会被解码
     * @param array $data 将要被解码的数据
     * @param bool $valuesOnly 是否只解码数组数值，如果为 false 那么所有下标和值都将被解码
     * @return array 解码后的数据
     * @see http://www.php.net/manual/en/function.urldecode.php
     */
    protected function urlDecode($data, $valuesOnly = true)
    {
        if (is_array($data)) {
            $d = [];
            foreach ($data as $key => $value) {
                if (!$valuesOnly) {
                    $key = $this->urlDecode($key, $valuesOnly);
                }
                $d[$key] = $this->urlDecode($value, $valuesOnly);
            }
            $data = $d;
        } elseif (is_string($data)) {
            $data = urldecode($data);
        }
        return $data;
    }

    /**
     * 判断是否是管理员登录状态
     *
     * @access public
     * @return bool
     */
    public function isAdmin()
    {
        $hasLogin = Typecho_Widget::widget('Widget_User')->hasLogin();
        if (!$hasLogin) {
            return false;
        }
        $isAdmin = Typecho_Widget::widget('Widget_User')->pass('administrator', true);
        return $isAdmin;
    }

    /**
     * 删除记录
     *
     * @access public
     * @return void
     */
    public function deleteLogs($ids)
    {
        foreach ($ids as $id) {
            $this->db->query($this->db->delete('table.access_log')
                     ->where('id = ?', $id)
            );
        }
    }

    /**
     * 获取首次进入网站时的来源
     *
     * @access public
     * @return string
     */
    public function getEntryPoint()
    {
        $entrypoint = $this->request->getReferer();
        if ($entrypoint == null) {
            $entrypoint = Typecho_Cookie::get('__typecho_access_entrypoint');
        }
        if (parse_url($entrypoint, PHP_URL_HOST) == parse_url(Helper::options()->siteUrl, PHP_URL_HOST)) {
            $entrypoint = null;
        }
        if ($entrypoint != null) {
            Typecho_Cookie::set('__typecho_access_entrypoint', $entrypoint);
        }
        return $entrypoint;
    }

    /**
     * 记录当前访问（管理员登录不会记录）
     *
     * @access public
     * @return void
     */
    public function writeLogs($archive = null, $url = null, $content_id = null, $meta_id = null)
    {
        if ($this->isAdmin()) {
            return;
        }
        if ($url == null) {
            $url = $this->request->getServer('REQUEST_URI');
        }
        $ip = $this->request->getIp();
        if ($ip == null) {
            $ip = '0.0.0.0';
        }
        $ip = bindec(decbin(ip2long($ip)));
        
        $entrypoint = $this->getEntryPoint();
        $referer    = $this->request->getReferer();
        $time       = Helper::options()->gmtTime + (Helper::options()->timezone - Helper::options()->serverTimezone);

        if ($archive != null) {
            $parsedArchive = $this->parseArchive($archive);
            $content_id = $parsedArchive['content_id'];
            $meta_id = $parsedArchive['meta_id'];
        } else {
            $content_id = is_numeric($content_id) ? $content_id : null;
            $meta_id = is_numeric($meta_id) ? $meta_id : null;
        }

        $rows = array(
            'ua'                => $this->ua->getUA(),
            'browser_id'        => $this->ua->getBrowserID(),
            'browser_version'   => $this->ua->getBrowserVersion(),
            'os_id'             => $this->ua->getOSID(),
            'os_version'        => $this->ua->getOSVersion(),
            'url'               => $url,
            'path'              => parse_url($url, PHP_URL_PATH),
            'query_string'      => parse_url($url, PHP_URL_QUERY),
            'ip'                => $ip,
            'referer'           => $referer,
            'referer_domain'    => parse_url($referer, PHP_URL_HOST),
            'entrypoint'        => $entrypoint,
            'entrypoint_domain' => parse_url($entrypoint, PHP_URL_HOST),
            'time'              => $time,
            'content_id'        => $content_id,
            'meta_id'           => $meta_id,
            'robot'             => $this->ua->isRobot() ? 1 : 0,
            'robot_id'          => $this->ua->getRobotID(),
            'robot_version'     => $this->ua->getRobotVersion(),
        );

        try {
            $this->db->query($this->db->insert('table.access_log')->rows($rows));
        } catch (Exception $e) {} catch (Typecho_Db_Query_Exception $e) {}
    }

    /**
     * 重新刷数据库，当遇到一些算法变更时可能需要用到
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function rewriteLogs() 
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select()->from('table.access_log'));
        foreach ($rows as $row) {
            $ua = new Access_UA($row['ua']);
            $row['browser_id'       ] = $ua->getBrowserID();
            $row['browser_version'  ] = $ua->getBrowserVersion();
            $row['os_id'            ] = $ua->getOSID();
            $row['os_version'       ] = $ua->getOSVersion();
            $row['robot'            ] = $ua->isRobot() ? 1 : 0;
            $row['robot_id'         ] = $ua->getRobotID();
            $row['robot_version'    ] = $ua->getRobotVersion();
            try {
                $db->query($db->update('table.access_log')->rows($row)->where('id = ?', $row['id']));
            } catch (Typecho_Db_Exception $e) {
                throw new Typecho_Plugin_Exception(_t('刷新数据库失败：%s。', $e->getMessage()));
            }
        }
    }

    /**
     * 解析archive对象
     * 
     * @access public
     * @return array
     */
    public function parseArchive($archive) 
    {
        // 暂定首页的meta_id为0
        $content_id = null;
        $meta_id = null;
        if ($archive->is('index')) {
            $meta_id = 0;
        } elseif ($archive->is('post') || $archive->is('page')) {
            $content_id = $archive->cid;
        } elseif ($archive->is('tag')) {
            $meta_id = $archive->tags[0]['mid'];
        } elseif ($archive->is('category')) {
            $meta_id = $archive->categories[0]['mid'];
        } elseif ($archive->is('archive', 404)) {}

        return array(
            'content_id'    => $content_id,
            'meta_id'       => $meta_id,
        );
    }

}
