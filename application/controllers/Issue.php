<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * 计划模块
 */
class Issue extends CI_Controller {

    /**
     * 项目ID
     */
    private $_projectid = 0;

    /**
     * 初始化，获取项目id
     */
    private function __init()
    {
        $projectid = $this->input->cookie('cits_curr_project');
        if ($projectid) {
            $projectid = $this->encryption->decrypt($projectid);
            if (!($projectid != 0 && ctype_digit($projectid))) {
                show_error('项目团队ID异常，请 <a href="/">返回首页</a> 重新选择项目团队', 500, '错误');
            } else {
                $this->_projectid = $projectid;
            }
        } else {
            show_error('无法获取项目团队信息（计划，任务，提测，bug四个模块操作前先在页面顶部选择项目），请 <a href="/">返回首页</a> 选择项目团队', 500, '错误');
        }
    }

    /**
     * 计划列表
     *
     * 默认显示所有项目
     */
    public function index()
    {
        $this->__init();

        $data['PAGE_TITLE'] = '计划列表';

        

        //刷新在线用户列表（埋点）
        $this->load->model('Model_online', 'online', TRUE);
        $this->online->refresh(UID);
        $onlineUsers = $this->online->users();
        $data['online_users'] = $onlineUsers;

        $this->load->view('plan', $data);
    }

    /**
     * 创建任务面板
     *
     * 创建任务面板，需要先解析计划ID
     */
    public function add()
    {
        $this->__init();

        $data['PAGE_TITLE'] = '创建任务';

        //获取传值
        $planid = $data['planid'] = $this->input->get('planid', TRUE);
        if ($planid) {
            $planid = $this->encryption->decrypt($planid);
            if (!($planid != 0 && ctype_digit($planid))) {
                show_error('计划id异常', 500, '错误');
            }
        }

        //读取系统配置信息
        $this->load->library('curl');
        $this->config->load('extension', TRUE);
        $system = $this->config->item('system', 'extension');
        $data['level'] = $this->config->item('level', 'extension');

        //根据项目ID获取计划列表
        $api = $this->curl->get($system['api_host'].'/plan/rows_by_projectid?id='.$this->_projectid);
        if ($api['httpcode'] == 200) {
            $output = json_decode($api['output'], true);
            if ($output['status']) {
                $data['planRows'] = $output['content']['data'];
            }
        } else {
            log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':读取计划API异常.HTTP_CODE['.$api['httpcode'].']');
            show_error('API异常.HTTP_CODE['.$api['httpcode'].']', 500, '错误');
        }

        $this->load->view('issue_add', $data);
    }

    /**
     * 写入任务信息
     *
     * 操作：写入任务信息，写入操作日志，通知受理人
     */
    public function add_ajax()
    {
        $this->__init();

        //验证输入
        $this->load->library(array('form_validation', 'curl'));
        $this->form_validation->set_rules('planid', '所属计划ID', 'trim|required',
            array('required' => '%s 不能为空')
        );
        $this->form_validation->set_rules('type', '类型', 'trim|required|is_natural_no_zero|max_length[1]',
            array(
                'required' => '%s 不能为空',
                'is_natural_no_zero' => '类型[ '.$this->input->post('type').' ]不符合规则',
                'max_length' => '类型[ '.$this->input->post('type').' ]太长了'
            )
        );
        $this->form_validation->set_rules('level', '优先级', 'trim|required|is_natural_no_zero|max_length[1]',
            array(
                'required' => '%s 不能为空',
                'is_natural_no_zero' => '优先级[ '.$this->input->post('level').' ]不符合规则',
                'max_length' => '优先级[ '.$this->input->post('level').' ]太长了'
            )
        );
        $this->form_validation->set_rules('issue_name', '任务标题', 'trim|required',
            array('required' => '%s 不能为空')
        );
        $this->form_validation->set_rules('issue_summary', '任务详情', 'trim');
        $this->form_validation->set_rules('issue_url', '相关链接', 'trim');
        $this->form_validation->set_rules('accept_user', '受理人ID', 'trim|required|is_natural_no_zero',
            array(
                'required' => '%s 不能为空',
                'is_natural_no_zero' => '类型[ '.$this->input->post('accept_user').' ]不符合规则'
            )
        );
        if ($this->form_validation->run() == FALSE) {
            log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':'.validation_errors());
            exit(json_encode(array('status' => false, 'error' => validation_errors())));
        }

        //解析planid
        $planid = $this->input->post('planid');
        if ($planid) {
            $planid = $this->encryption->decrypt($planid);
            if (!($planid != 0 && ctype_digit($planid))) {
                log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':计划id错误');
                exit(json_encode(array('status' => false, 'error' => '计划id错误')));
            }
        }

        //写入数据
        $Post_data['issue_name'] = $this->input->post('issue_name');
        $Post_data['issue_summary'] = $this->input->post('issue_summary');
        $Post_data['project_id'] = $this->_projectid;
        $Post_data['plan_id'] = $planid;
        $Post_data['level'] = $this->input->post('level');
        $Post_data['type'] = $this->input->post('type');
        $Post_data['add_user'] = UID;
        $Post_data['accept_user'] = $this->input->post('accept_user');
        //如果有相关链接就序列化它
        if ($this->input->post('issue_url')) {
            $Post_data['url'] = serialize(array_filter(explode(PHP_EOL, $this->input->post('issue_url'))));
        }
        $this->config->load('extension', TRUE);
        $system = $this->config->item('system', 'extension');
        $api = $this->curl->post($system['api_host'].'/issue/write', $Post_data);
        if ($api['httpcode'] == 200) {
            $output = json_decode($api['output'], true);
            if ($output['status']) {
                //写入操作日志
                $Post_data_handle['sender'] = UID;
                $Post_data_handle['action'] = '创建';
                $Post_data_handle['target'] = $output['content'];
                $Post_data_handle['target_type'] = 3;
                $Post_data_handle['type'] = 1;
                $Post_data_handle['content'] = $this->input->post('issue_name');
                $api = $this->curl->post($system['api_host'].'/handle/write', $Post_data_handle);
                if ($api['httpcode'] == 200) {
                    $output_handle = json_decode($api['output'], true);
                    if ($output_handle['status']) {
                        //通知受理人
                        $Post_data_notify['user'] = $this->input->post('accept_user');
                        $Post_data_notify['log_id'] = $output_handle['content'];
                        $api = $this->curl->post($system['api_host'].'/notify/write', $Post_data_notify);
                        if ($api['httpcode'] == 200) {
                            $output_notify = json_decode($api['output'], true);
                            if (!$output_notify['status']) {
                                log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':写入通知异常-'.$output_notify['error']);
                            }
                        } else {
                            log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':写入通知API异常.HTTP_CODE['.$api['httpcode'].']');
                        }
                    } else {
                        log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':写入操作日志异常-'.$output_handle['error']);
                    }
                } else {
                    log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':写入操作日志API异常.HTTP_CODE['.$api['httpcode'].']');
                }
                exit(json_encode(array('status' => true, 'message' => '创建成功', 'content' => $output['content'])));
            } else {
                log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':创建失败：'.$output['error']);
                exit(json_encode(array('status' => false, 'error' => '创建失败：'.$output['error'])));
            }
        } else {
            log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':写入任务API异常.HTTP_CODE['.$api['httpcode'].']');
            exit(json_encode(array('status' => false, 'error' => 'API异常.HTTP_CODE['.$api['httpcode'].']')));
        }
    }

    /**
     * 编辑任务
     *
     * 根据任务ID读取任务信息。
     */
    public function edit()
    {
        $this->__init();

    }

    /**
     * 写入编辑信息
     *
     * 步骤：写入编辑后的任务信息，写入操作日志，通知关注任务的人任务信息变更了。
     */
    public function edit_ajax()
    {
        $this->__init();

    }

    /**
     * 删除任务
     *
     * 为了保证数据的完整性，防止误删采取软删除。
     */
    public function del()
    {
        $this->__init();

    }

    /**
     * 任务详情
     *
     * 显示任务详情，读取相关的提测列表，读取相关的BUG列表。
     */
    public function view()
    {
        $this->__init();

        //解析url传值
        $this->load->helper('alphaid');
        $id = $this->uri->segment(3, 0);
        $id = alphaid($id, 1);

        //根据id获取任务信息
        $this->load->library('curl');
        $this->config->load('extension', TRUE);
        $system = $this->config->item('system', 'extension');
        $api = $this->curl->get($system['api_host'].'/issue/profile?id='.$id.'&access_token='.$system['access_token']);
        if ($api['httpcode'] == 200) {
            $output = json_decode($api['output'], true);
            if (!$output['status']) {
                log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':任务不存在。任务id:['.$id.']');
                show_error('任务不存在', 500, '错误');
            }
        } else {
            log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':读取任务详情API异常.HTTP_CODE['.$api['httpcode'].']');
            show_error('API异常.HTTP_CODE['.$api['httpcode'].']', 500, '错误');
        }
        $Issue_profile = $output['content'];

        $data['PAGE_TITLE'] = $Issue_profile['issue_name'].' - 任务详情';

        //读取相关提测记录
        $api = $this->curl->get($system['api_host'].'/commit/get_rows_by_issue?id='.$id.'&access_token='.$system['access_token']);
        if ($api['httpcode'] == 200) {
            $output = json_decode($api['output'], true);
            if ($output['status']) {
                print_r($output);
            }
        } else {
            log_message('error', $this->router->fetch_class().'/'.$this->router->fetch_method().':读取提测列表API异常.HTTP_CODE['.$api['httpcode'].']');
            show_error('API异常.HTTP_CODE['.$api['httpcode'].']', 500, '错误');
        }

        exit();


        $data = array(
            'PAGE_TITLE' => '', //页面标题
            'row' => array(), //任务详情
            'test' => array(), //任务相关的提测
            'total_rows' => 0, //任务相关的提测数量
            'repos' => array(), //代码库缓存文件
            'users' => array(), //用户信息缓存文件
            'shareUsers' => array(), //贡献代码的用户信息
            'bug' => array(),
            'bug_total_rows' => 0
        );

        //获取相关提测记录
        $this->load->model('Model_test', 'test', TRUE);
        $rows = $this->test->listByIssueId($id);
        if ($rows['total']) {
            $data['test'] = $rows['data'];
            $data['total_rows'] = $rows['total'];
        }

        //计算提测成功率
        $data['rate'] = '无提测数据用于计算';
        $testIdArr = array();
        if ($rows['data']) {
            foreach ($rows['data'] as $key => $value) {
                if (isset($testIdArr[$value['repos_id']])) {
                    $testIdArr[$value['repos_id']] += 1;
                } else {
                    $testIdArr[$value['repos_id']] = 1;
                }
            }
            $maxTest = max($testIdArr);
            $data['rate'] = sprintf("%.2f", 1/$maxTest);
        }

        //获取相关BUG记录
        $this->load->model('Model_bug', 'bug', TRUE);
        $rows = $this->bug->listByIssueId($id);
        if ($rows['total']) {
            $data['bug'] = $rows['data'];
            $data['bug_total_rows'] = $rows['total'];
        }
        
        //验证BUG是否都已经处理
        $data['fixedFlag'] = 1;
        if ($rows['data']) {
            foreach ($rows['data'] as $key => $value) {
                if ($value['state'] == '0' || $value['state'] == '1') {
                    $data['fixedFlag'] = 0;
                    break;
                }
            }
        }

        //将任务关注人转为数组
        $data['row']['watch'] = unserialize($data['row']['watch']);

        //读取所属计划
        $data['plan'] = array();
        if ($data['row']['plan_id']) {
            $this->load->model('Model_plan', 'plan', TRUE);
            $data['plan'] = $this->plan->fetchOne($data['row']['plan_id']);
        }

        
        //读取受理信息
        $this->load->model('Model_accept', 'accept', TRUE);
        $data['acceptUsers'] = $this->accept->users($id);
        
        //载入文件缓存
        if (file_exists('./cache/repos.conf.php')) {
            require './cache/repos.conf.php';
            $data['repos'] = $repos;
        }
        if (file_exists('./cache/users.conf.php')) {
            require './cache/users.conf.php';
            $data['users'] = $users;
        }

        //读取任务相关的评论
        $this->load->model('Model_issuecomment', 'issuecomment', TRUE);
        $rows = $this->issuecomment->rows($id);
        $data['comment'] = $rows['data'];

        //载入配置信息
        $this->config->load('extension', TRUE);
        $data['level'] = $this->config->item('level', 'extension');
        $data['env'] = $this->config->item('env', 'extension');

        $this->load->view('issue_view', $data);

    }

    /**
     * 计算提测成功率
     *
     * 算法：
     */
    public function rate()
    {
        $this->__init();

    }
}