<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Game AdminPanel (АдминПанель)
 *
 * 
 *
 * @package		Game AdminPanel
 * @author		Nikita Kuznetsov (ET-NiK)
 * @copyright	Copyright (c) 2014, Nikita Kuznetsov (http://hldm.org)
 * @license		http://www.gameap.ru/license.html
 * @link		http://www.gameap.ru
 * @filesource	
 */

class Settings extends CI_Controller {
	
	//Template
	var $tpl_data = array();
	
	var $user_data = array();
	var $server_data = array();
	
	public function __construct()
    {
        parent::__construct();
		
		$this->load->database();
        $this->load->model('users');
        $this->lang->load('settings');

        if($this->users->check_user()){
			
			 $this->load->model('servers');
			 $this->load->model('servers/game_types');
			 $this->load->library('form_validation');
			
			//Base Template
			$this->tpl_data['title'] 		= lang('settings_title_index');
			$this->tpl_data['heading'] 		= lang('settings_heading_index');
			$this->tpl_data['content'] 		= '';
			$this->tpl_data['menu'] 		= $this->parser->parse('menu.html', $this->tpl_data, true);
			$this->tpl_data['profile'] 		= $this->parser->parse('profile.html', $this->users->tpl_userdata(), true);
        
        }else{
            redirect('auth');
        }
    }
    
	//--------------------------------------------------------------------------
    
	// Отображение информационного сообщения
    function _show_message($message = false, $link = false, $link_text = false)
    {
        
        if (!$message) {
			$message = lang('error');
		}
		
        if (!$link) {
			$link = 'javascript:history.back()';
		}
		
		if (!$link_text) {
			$link_text = lang('back');
		}

        $local_tpl['message'] = $message;
        $local_tpl['link'] = $link;
        $local_tpl['back_link_txt'] = $link_text;
        $this->tpl_data['content'] = $this->parser->parse('info.html', $local_tpl, true);
        $this->parser->parse('main.html', $this->tpl_data);
    }
	
	// ----------------------------------------------------------------

    /**
     * Настройки сервера
    */
    public function server($server_id = false)
    {

		if(!$server_id) {
			$this->_show_message('Сервер не найден');
			return false;
		}
		
		$server_id = (int)$server_id;
		
		/* Получение данных сервера и привилегий на сервер */
		$this->servers->server_data = $this->servers->get_server_data($server_id);
		$this->users->get_server_privileges($server_id);
		
		if(!$this->servers->server_data) {
			$this->_show_message(lang('settings_server_not_found'));
			return false;
		}
		
		/* Пользователь должен быть админом либо иметь привилегии настройки */			
		if(!$this->users->auth_data['is_admin'] && !$this->users->auth_servers_privileges['SERVER_SETTINGS']) {
			$this->_show_message(lang('settings_not_privileges_for_server'));
			return false;
		}
		
		$server_settings = $this->servers->get_server_settings($server_id);

		if (!$this->input->post('save')){
			
			/* Отображение формы */
			$num = -1;
			foreach ($server_settings as $sett_id => $value) {
				$num++;
		
				$local_tpl['settings'][$num]['input_field'] = form_checkbox($sett_id, '1', $value);
				$local_tpl['settings'][$num]['human_name'] = $this->servers->all_settings[$sett_id];
			}
			
			/* Допустимые алиасы */
			if (isset($this->servers->server_data['aliases_list']) && $this->servers->server_data['aliases_list'] != '') {
				$allowable_aliases = json_decode($this->servers->server_data['aliases_list'], true);
			} else {
				$allowable_aliases = array();
			}
			
			if (!$allowable_aliases) {
				$allowable_aliases = array();
			}
			
			/* Значения алиасов на сервере */
			$aliases_values =& $this->servers->server_data['aliases_values'];
			
			/* Отображение алиасов */
			if($allowable_aliases && !empty($allowable_aliases)) {
				foreach ($allowable_aliases as $alias) {

					if(!$this->users->auth_data['is_admin'] && !$this->users->auth_privileges['srv_global'] && $alias['only_admins']) {
						/* Алиас могут редактировать только администраторы */
						continue;
					}
					
					$num++; // Отсчет продолжаем, не сбрасываем
					
					// Задаем правила проверки для алиаса
					$this->form_validation->set_rules('alias_' . $alias['alias'], $alias['desc'], 'trim|max_length[64]|xss_clean');
					
					if(isset($aliases_values[$alias['alias']])) {
						$value_alias = $aliases_values[$alias['alias']];
					} else {
						$value_alias = '';
					}
					
					$data = array(
						  'name'        => 'alias_' . $alias['alias'],
						  'value'       => $value_alias,
						  'maxlength'   => '64',
						  'size'        => '30',
						);

					$local_tpl['settings'][$num]['input_field'] =  form_input($data);
					$local_tpl['settings'][$num]['human_name'] = $alias['desc'];
				}
			}
			
			$local_tpl['server_id'] = $server_id;

			$this->tpl_data['content'] .= $this->parser->parse('settings/server.html', $local_tpl, true);
		} else {
			/* Сохранение настроек */
			
			$log_data['log_data'] = '';
			

            foreach ($this->servers->all_settings as $sett_id => $value) {

				$value = (bool)$this->input->post($sett_id, true);
				$this->servers->set_server_settings($sett_id, $value, $server_id);
				
				$log_data['log_data'] .= $sett_id . ' : ' . (int)$value . "\n";
            }
            
            /* Допустимые алиасы */
			$allowable_aliases = json_decode($this->servers->server_data['aliases_list'], true);
			
			/* Значения алиасов на сервере */
			$aliases_values =& $this->servers->server_data['aliases_values'];
			
			/* Прогон по алиасам */
			if($allowable_aliases && !empty($allowable_aliases)) {
				foreach ($allowable_aliases as $alias) {

					if(!$this->users->auth_data['is_admin'] && !$this->users->auth_privileges['srv_global'] && $alias['only_admins']) {
						/* Алиас могут редактировать только администраторы */
						continue;
					}
					
					/* Для безопасности запрещаем пробелы, табы и кавычки */
					//~ $alias_arr = explode(' ', $this->input->post('alias_' . $alias['alias'], true));
					//~ $aliases_values[$alias['alias']] = $alias_arr[0];
					$aliases_values[$alias['alias']] = $this->input->post('alias_' . $alias['alias'], true);
					$aliases_values[$alias['alias']] = str_replace('\'', '', $aliases_values[$alias['alias']]);
					$aliases_values[$alias['alias']] = str_replace('&', '', $aliases_values[$alias['alias']]);
					$aliases_values[$alias['alias']] = str_replace('|', '', $aliases_values[$alias['alias']]);
					$aliases_values[$alias['alias']] = str_replace('"', '', $aliases_values[$alias['alias']]);
					$aliases_values[$alias['alias']] = str_replace('	', ' ', $aliases_values[$alias['alias']]);
					
					$log_data['log_data'] .= 'alias_' . $alias['alias'] . ' : ' . $aliases_values[$alias['alias']] . "\n";
				}
			}
			
			// Отправляем алиасы на сервер
			$sql_data['aliases'] = json_encode($aliases_values);
			$this->servers->edit_game_server($server_id, $sql_data);

            // Сохраняем логи
			$log_data['type'] = 'server_settings';
			$log_data['command'] = 'edit_settings';
			$log_data['user_name'] = $this->users->auth_login;
			$log_data['server_id'] = $server_id;
			$log_data['msg'] = 'Edit settings';
			$this->panel_log->save_log($log_data);
            
            $this->_show_message(lang('settings_saved'), site_url('admin/server_control/main/' . $server_id), lang('next'));
            return true;
            
		}
		
		$this->parser->parse('main.html', $this->tpl_data);
	}
	
	// ----------------------------------------------------------------

    /**
     * Задает пользовательский фильтр на серверы
    */
    public function set_filter($filter_name = 'servers_list')
    {
		
		switch($filter_name) {
			case 'servers_list':
				$this->form_validation->set_rules('filter_name', lang('name'), 'trim|xss_clean');
				$this->form_validation->set_rules('filter_ip', lang('ip'), 'xss_clean');
				$this->form_validation->set_rules('filter_game', lang('game'), 'xss_clean');
				break;
			
			case 'panel_log':
				$this->form_validation->set_rules('filter_type', lang('action'), 'trim|xss_clean');
				$this->form_validation->set_rules('filter_command', lang('command'), 'trim|xss_clean');
				$this->form_validation->set_rules('filter_user', lang('user'), 'trim|xss_clean');
				$this->form_validation->set_rules('filter_contents', lang('contents'), 'trim|xss_clean');
				break;
				
			case 'users_list':
				$this->form_validation->set_rules('filter_login', lang('login'), 'trim|xss_clean');
				
				$this->form_validation->set_rules('filter_register_before', lang('lang_profile_registered'), 'trim|xss_clean');
				$this->form_validation->set_rules('filter_register_after', lang('lang_profile_registered'), 'trim|xss_clean');
				
				$this->form_validation->set_rules('filter_last_visit_before', lang('lang_profile_last_visit'), 'trim|xss_clean');
				$this->form_validation->set_rules('filter_last_visit_after', lang('lang_profile_last_visit'), 'trim|xss_clean');
				break;
				
			default:
				redirect($_SERVER['HTTP_REFERER']);
				break;
			
		}
		
		if($this->form_validation->run() == false) {
			
			if (validation_errors()) {
				$this->_show_message(validation_errors());
				return false;
			}

		} else {
			$reset = (bool) $this->input->post('reset');

			switch($filter_name) {
				case 'servers_list':
					$filter['name'] = $reset ? '' : $this->input->post('filter_name');
					$filter['ip'] 	= $reset ? '' : $this->input->post('filter_ip');
					$filter['game'] = $reset ? '' : $this->input->post('filter_game');
					break;
				
				case 'panel_log':
					$filter['type'] 	= $reset ? '' : $this->input->post('filter_action');
					$filter['command'] 	= $reset ? '' : $this->input->post('filter_command');
					$filter['user_name']= $reset ? '' : $this->input->post('filter_user');
					$filter['contents'] = $reset ? '' : $this->input->post('filter_contents');
					break;
					
				case 'users_list':
					$this->load->helper('date');
					
					$filter['login'] 			= $reset ? '' : $this->input->post('filter_login');
					
					$filter['register_before'] 	= $reset ? '' : human_to_unix($this->input->post('filter_register_before'));
					$filter['register_after']	= $reset ? '' : human_to_unix($this->input->post('filter_register_after'));
					
					$filter['last_visit_before']= $reset ? '' : human_to_unix($this->input->post('filter_last_visit_before'));
					$filter['last_visit_after'] = $reset ? '' : human_to_unix($this->input->post('filter_last_visit_after'));
					break;
			}

			$this->users->update_filter($filter_name, $filter);
			redirect($_SERVER['HTTP_REFERER']);
		}
	}
	
	
    
}
