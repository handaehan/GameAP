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
class Servers extends CI_Model {

    public $server_id   			= 0;			// ИД сервера
    
	private $_filter_servers_list	= array('name' => false, 'ip' => false, 'game' => false);		// Фильтр списка серверов
	private $_fields 				= '';			// Поля, которые нужно выбрать
	private $_order_by				= array('field' => 'id', 'order' => 'asc');
	public $servers_list 			= array();		// Список игровых серверов
	
    public $server_data 			= array();		// Данные сервера
    public $server_query_data 		= array();		// query данные сервера
    public $server_ds_data 			= array();		// Данные DS игрового сервера
    public $server_game_data 		= array();		// Данные игры к которой принадлежит сервер
    
    public $all_settings = array(
		'SERVER_AUTOSTART'			=> 'Автостарт сервера в случае его падения (через cron)',
		'SERVER_RCON_AUTOCHANGE' 	=> 'Автоматическая смена rcon пароля, в случае если в админпанели и на сервере он не совпадает',
    );
    
    public $server_settings 		= array();
    //~ public $commands			= array(); // Команды, которые отправлялись на сервер
    public $errors 					= ''; 	// Строка с ошибкой (если имеются)
    
    private $_exec_file				= array('linux' => './server.sh', 'windows' => 'server.exe');

    function __construct()
    {
        // Call the Model constructor
        parent::__construct();
        
        $this->load->helper('safety');
        $this->load->library('encrypt');
    }
    
	//-----------------------------------------------------------
	
	private function _strip_quotes($string) 
	{
		$string = str_replace('"', '', $string);
		$string = str_replace('\'', '', $string);
		
		return $string;
	}
	
	//-----------------------------------------------------------
	
	private function _apply_filter()
	{
		!$this->_filter_servers_list['name'] OR $this->db->like('name', $this->_filter_servers_list['name']);
		
		if (!empty($this->_filter_servers_list['ip'])) {
			if (is_array($this->_filter_servers_list['ip'])) {
				$this->db->where_in('server_ip', $this->_filter_servers_list['ip']);
			} else {
				$this->db->where('server_ip', $this->_filter_servers_list['ip']);
			}
		}
		
		if (!empty($this->_filter_servers_list['game'])) {
			if (is_array($this->_filter_servers_list['game'])) {
				$this->db->where_in('game', $this->_filter_servers_list['game']);
			} else {
				$this->db->where('game', $this->_filter_servers_list['game']);
			}
		}
	}
	
	//-----------------------------------------------------------
	
	private function _get_engine() 
	{
		if (!isset($this->games->games_list)) {
			$this->load->models('servers/games');
			$this->games->get_games_list();
		}
			
		/* Получение id игры в массиве */
		$i = 0;
		$count = count($this->games->games_list);
		while($i < $count) {
			if ($this->server_data['game'] == $this->games->games_list[$i]['code']) {
				$game_arr_id = $i;
				break;
			}
			$i++;
		}
			
		$engine 		= $this->games->games_list[$game_arr_id]['engine'];
		$engine_version = $this->games->games_list[$game_arr_id]['engine_version'];
		
		return array($engine, $engine_version);
	}
	
	//-----------------------------------------------------------
    
    /*
     * Проверяет директорию на необходимые права
    */
	private function _check_path($path) {
		
		if (!is_dir($path)) {
			/* Это не директория */
			$this->errors = "Dir " . $path . " not found";
			return false;
		}

		return true;
	}
	
	//-----------------------------------------------------------
    
    /*
     * Проверяет файл на необходимые права
     * 
     * @param str - путь к локальному файлу
     * @return bool
     * 
    */
	private function _check_file($file) {
		if (!file_exists($file)) {
			$this->errors = 'Error: ' . $file . ' file not found';
			return false;
		}
		
		if (!is_executable($file)) {
			$this->errors = 'Error: ' . $file . ' file not executable';
			return false;
		}

		return true;
	}
	
	//-----------------------------------------------------------
	
	public function replace_shotcodes($command, &$server_data) 
	{
		if (is_array($command)) {
			return $this->_replace_shotcodes_in_array($command, $server_data);
		}
		else {
			return $this->_replace_shotcodes_in_string($command, $server_data);
		}
	}
	
	//-----------------------------------------------------------
	
	/*
	 * Замена шоткодов в команде
	*/
	private function _replace_shotcodes_in_string($command, &$server_data)
	{
		$this->load->helper('ds');
		return replace_shotcodes($command, $server_data);
	}

	//-----------------------------------------------------------
	
	/*
	 * Замена шоткодов в команде
	*/
	private function _replace_shotcodes_in_array($command, &$server_data)
	{
		foreach($command as &$str) { 
			$str = $this->_replace_shotcodes_in_string($str, $server_data); 
		}

		return $command;
	}
	
	//-----------------------------------------------------------
    
    /*
     * Генерирует команду для отправки на сервер
     * 
     * 
    */
    function command_generate($server_data, $type = 'start')
    {
		
		/* Получение команд из данных сервера */
		switch($type){
			case 'start':
				$command = $server_data['script_start'];
				break;
			case 'stop':
				$command = $server_data['script_stop'];
				break;
			case 'restart':
				$command = $server_data['script_restart'];
				break;
			case 'update':
				$command = $server_data['script_update'];
				break;
			case 'get_console':
				$command = $server_data['script_get_console'];
				break;
			default:
				return false;
		}

		return $command;
	}

    
    /*
     * Запуск сервера
     * 
     * @param array - данные сервера или его id
     *
    */
    function start($server_data)
    {
		$this->load->helper('ds');
		
		if(!is_array($server_data)) {
			// был передан id, получаем данные сервера
			$server_data = $this->get_server_data($server_data, false, true, true);
		}
		
		$command = $this->command_generate($server_data, 'start');
		return send_command($command, $server_data);
	}
	
	
	//-----------------------------------------------------------	
	/*
     * Остановка сервера
     * 
     * @param array - данные сервера
     *
    */
    function stop($server_data)
    {
		$this->load->helper('ds');
		
		if(!is_array($server_data)) {
			// был передан id, получаем данные сервера
			$server_data = $this->get_server_data($server_data);
		}
		
		$command = $this->command_generate($server_data, 'stop');
		return send_command($command, $server_data);
	}
	
	
	//-----------------------------------------------------------	
	/*
     * Перезапуск сервера
     * 
     * @param array - данные сервера
     *
    */
    function restart($server_data)
    {
		$this->load->helper('ds');
		
		if(!is_array($server_data)) {
			// был передан id, получаем данные сервера
			$server_data = $this->get_server_data($server_data, false, true, true);
		}
		
		$command = $this->command_generate($server_data, 'restart');
		return send_command($command, $server_data);
	}

	//-----------------------------------------------------------	
	/*
     * Добавление нового сервера
     * 
     *
    */
    function add_game_server($data)
    {
		$this->load->helper('string');
		$this->load->model('games');
		
		if (isset($data['rcon'])) {
			$data['rcon'] = $this->encrypt->encode($data['rcon']);
		}
		
		/* Присваиваем имя screen  */
		$data['screen_name'] = (!isset($data['screen_name'])) ? $data['game'] . '_' . random_string('alnum', 6) . '_' . $data['server_port'] : $data['screen_name'];
		
		return (bool)$this->db->insert('servers', $data);
	}

	//-----------------------------------------------------------	
	/*
     * Редактирование сервера
     * 
     *
    */
    function edit_game_server($id, $data)
    {
		$this->db->where('id', $id);
        $this->gameap_hooks->run('pre_server_edit', array('server_id' => $id, 'server_data' => &$data));
		
		if (isset($data['rcon'])) {
			$data['rcon'] = $this->encrypt->encode($data['rcon']);
		}
		
		if ((bool)$this->db->update('servers', $data)) {
            $this->gameap_hooks->run('post_server_edit', array('server_id' => $id, 'server_data' => &$data));
            return true;
        } else {
            return false;
        }
	}
	
	/**
     * Обновляет поле с данными для модулей
     * 
     * @param id 	 	id сервера
     * @param array 	новые данные
     * @param string	имя модуля
     * @param bool
     * 
     * @return bool
     *
    */
	function update_modules_data($id, $data, $module_name, $erase = false)
	{
		$server_data = $this->get_server_data($id, true, true, true);
		
		if (!$erase) {
			$server_data['modules_data'][$module_name] = isset($server_data['modules_data'][$module_name]) && is_array($server_data['modules_data'][$module_name])
													? array_merge($server_data['modules_data'][$module_name], $data)
													: $data;
		}
		else {
			$server_data['modules_data'][$module_name] = $data;
		}
													
		$sql_data['modules_data'] = json_encode($server_data['modules_data']);
		
		return (bool)$this->edit_game_server($id, $sql_data);
	}

	//-----------------------------------------------------------	
	/*
     * Удаление сервера
     * 
     *
    */
    function delete_game_server($id)
    {
        $this->gameap_hooks->run('pre_server_delete', array('server_id' => $id));

        if ($this->db->delete('servers', array('id' => $id))) {
			
			$this->db->delete('servers_privileges', array('server_id' => $id));
			$this->db->delete('logs', array('server_id' => $id));

            $this->gameap_hooks->run('post_server_delete', array('server_id' => $id));
			
			return true;
		} else {
			return false;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
     * Получение списка серверов
     * Алиас $this->get_servers_list()
     * 
     * @param int - id пользователя для которого получаем серверы
     * @param str - привилегия пользователя
     * @param array - where для запроса sql
     *
    */
	function get_list($user_id = false, $privilege_name = 'VIEW', $where = array('enabled' => '1', 'installed' => '1', ))
	{
		return $this->get_servers_list($user_id, $privilege_name, $where);
	}
	
	//-----------------------------------------------------------
	
	/**
     * Получение списка серверов
     * Алиас $this->get_servers_list()
     * 
     * @param int - id пользователя для которого получаем серверы
     * @param str - привилегия пользователя
     * @param array - where для запроса sql
     *
    */
	function get_server_list($user_id = false, $privilege_name = 'VIEW', $where = array('enabled' => '1', 'installed' => '1', ))
	{
		return $this->get_servers_list($user_id, $privilege_name, $where);
	}
	
	//-----------------------------------------------------------
	
	/**
     * Задает фильтры для получения серверов с определенными данными
    */
	function set_filter($filter)
	{
		if (is_array($filter)) {
			$this->_filter_servers_list['name'] = (isset($filter['name']) && $filter['name']) ? $filter['name'] : null;
			$this->_filter_servers_list['ip'] 	= (isset($filter['ip']) && $filter['ip']) ? $filter['ip'] : null;
			$this->_filter_servers_list['game'] = (isset($filter['game']) && $filter['game']) ? $filter['game'] : null;
		}
	}
	
	//-----------------------------------------------------------
	
	/**
	 * Сортировка для списка серверов
	 */
	function order_by($field, $order = 'asc')
	{
		$this->_order_by['field'] = $field;
		$this->_order_by['order'] = $order;
	}

	//-----------------------------------------------------------
	
	/**
     * Получение списка серверов
     * 
     * @param int - id пользователя для которого получаем серверы
     * @param str - привилегия пользователя
     * @param array - where для запроса sql
     *
    */
    function get_servers_list($user_id = false, $privilege_name = 'VIEW', $where = array('enabled' => '1', 'installed' => '1'), $limit = 99999, $offset = 0, $engine = false, $engine_version = false)
    {
		/* Если задан движок, то получаем список игр на этом движке,
		 * а после выбираем серверы только этих игр 
		 */
		$games = array();
		
		// Обнуление массива
		$this->servers_list = array();

		if ($engine) {
			$this->db->where('engine', $engine);
			if ($engine_version) {$this->db->where('engine_version', $engine_version);}
			
			$this->db->select('code');
			$query = $this->db->get('games');
			
			$games_list = $query->result_array();
			
			foreach($games_list as &$game_data) {
				$games[] = $game_data['code'];
			}
		}
		
		/* 
		 * Если user_id не задан или пользователь является администратором, то получаем все серверы
		 * Если задан, то получаем лишь серверы на которые у пользователя есть привилегия $privilege_name
		*/
		if (!$user_id OR ($privilege_name == 'VIEW' && $this->users->auth_data['is_admin'])) {
			if (!empty($games)) { $this->db->where_in('game', $games); }
			$this->db->where($where);
			
			// Приминение фильтров
			$this->_apply_filter();
			
			// Сортировка
			empty($this->_order_by) OR $this->db->order_by($this->_order_by['field'], $this->_order_by['order']);

			// Выбор полей
			!$this->_fields OR $this->db->select($this->_fields);
			
			// Сброс полей
			$this->_fields = '';
			
			$query = $this->db->get('servers');
		} else {
			
				/*
				 * Выбираются данные из таблицы servers_privileges 
				 * для пользователя $user_id со следующими привилегиями:
				 * privilege_name = $privilege_name
				 * privilege_value = 1	(разрешено)
				*/
				$query = $this->db->get_where('servers_privileges', array('user_id' => $user_id));
				
				if ($query->num_rows > 0) {
					
					$servers = array();

					//~ $this->db->where($where);
					foreach ($query->result_array() as $privileges) {
						
						$json_decode = json_decode($privileges['privileges'], true);
						
						if ($json_decode) {
							if ($json_decode[$privilege_name] == 1) {
								$servers[] = $privileges['server_id'];
							}
						}
					}
					
					if (empty($servers)) {
						$this->servers_list = array();
						return NULL;
					}
					
					$this->db->where_in('id', $servers);
					if (!empty($games)) { $this->db->where_in('game', $games); }
					
				} else {
					/* Количество серверов = 0 */
					
					/* 
					 * Чтобы избавиться от некоторых уязвимостей, связанных с бесправными пользователями
					 * у которых нет серверов, но при этом они отображаются в списке
					*/
					$this->servers_list = array();
					return NULL;
				}
				
				$this->_apply_filter();
				
				if (is_array($where) && !empty($where)) { 
					$this->db->where($where);
				}
				
				!$this->_fields OR $this->db->select($this->_fields);
				
				// Сброс полей
				$this->_fields = '';
				
				$query = $this->db->get('servers');
			}
			
			if ($query->num_rows <= 0) {
				/* Количество серверов = 0 */
				
				/* 
				 * Чтобы избавиться от некоторых уязвимостей, связанных с бесправными пользователями
				 * у которых нет серверов, но при этом они отображаются в списке
				*/
				$this->servers_list = array();
				return NULL;
			}

			$server_list = array();
			$i = 0;
			foreach ($query->result_array() as $server_data) {
				
				$server_list[$i] = $server_data;
				
				if (isset($server_data['server_port']) && !$server_data['query_port']) {
					$server_list[$i]['query_port'] = $server_data['server_port'];
				}
				
				if (isset($server_data['server_port']) && !$server_data['rcon_port']) {
					$server_list[$i]['rcon_port'] = $server_data['server_port'];
				}
				
				$i++;
			}

			$this->servers_list = $server_list;
			return $this->servers_list;
	}

	
	//-----------------------------------------------------------
	
	/**
     * Получение данных сервера
     * 
     * @param int - id сервера
     * @param bool - если true, то данные выделенного сервера получены не будут
     * @param bool - если true, то данные игры получены не будут
     * @param bool - если true, то данные типа игры получены не будут
     * 
     * @return array
     *
    */
    function get_server_data($server_id, $no_get_ds = false, $no_get_game = false, $no_get_gt = false)
    {
		// Загрузка необходимых моделей
		$this->load->model('servers/dedicated_servers');
		$this->load->model('servers/games');
		$this->load->model('servers/game_types');
		
		$this->load->library('encrypt');

		$query = $this->db->get_where('servers', array('id' => $server_id), 1);

		$this->server_data = $query->row_array();
		
		if($query->num_rows == 0) {
			return false;
		}
		
		if (!$this->server_data['ds_id']) {
			return false;
		}
		
		/* Записываем переменную в список */
		//~ $this->servers_list['0'] = $this->server_data;
		
		/* Расшифровываем RCON пароль */
		$this->server_data['rcon'] = $this->encrypt->decode($this->server_data['rcon']);
		
		/* Данные алиасов в массив */
		$this->server_data['aliases_values'] = json_decode($this->server_data['aliases'], true);

		if (!is_array($this->server_data['aliases_values'])) {
			$this->server_data['aliases_values'] = array();
		}
		
		if (!empty($this->server_data['modules_data'])) {
			$this->server_data['modules_data'] = json_decode($this->server_data['modules_data'], true);
		} else {
			$this->server_data['modules_data'] = array();
		}
		
		// Значение RCON пароля из алиаса
		if (preg_match('/^\{alias_([a-z\-\_]+)\}$/', $this->server_data['rcon'], $m)) {
			$this->server_data['rcon'] = isset($this->server_data['aliases_values'][ $m[1] ]) ? $this->server_data['aliases_values'][ $m[1] ] : '' ;
		}
		
		/* Получаем query и rcon порты */
		if (!$this->server_data['query_port']) {
			$this->server_data['query_port'] = $this->server_data['server_port'];
		}
		
		if (!$this->server_data['rcon_port']) {
			$this->server_data['rcon_port'] = $this->server_data['server_port'];
		}

		/* 
		 * Получение информации об удаленном сервере
		 * 
		 * Необходимо, чтобы был указан ds_id (ID выделенного сервере)
		 * если он будет равен 0 или не будет указан, то сервер
		 * принимается за локальный
		 * 
		*/
		if (!$no_get_ds && $this->server_data['ds_id']) {

			$where = array('id' => $this->server_data['ds_id']);
			$this->dedicated_servers->get_ds_list($where, 1);
			
			$this->server_ds_data =& $this->dedicated_servers->ds_list['0'];
			
			// Данные для игрового сервера из машины
			$this->server_data['os'] 			= strtolower($this->server_ds_data['os']);
			$this->server_data['local_server'] 	= $this->server_ds_data['local_server'];
			
			$this->server_data['ds_disabled'] 	= $this->server_ds_data['disabled'];

			$this->server_data['ftp_host']		= $this->server_ds_data['ftp_host'];
			$this->server_data['ftp_login'] 	= $this->server_ds_data['ftp_login'];
			$this->server_data['ftp_password'] 	= $this->server_ds_data['ftp_password'];
			$this->server_data['ftp_passwd'] 	= $this->server_ds_data['ftp_password'];
			$this->server_data['ftp_path'] 		= $this->server_ds_data['ftp_path'];
			
			$this->server_data['ssh_host']		= $this->server_ds_data['ssh_host'];
			$this->server_data['ssh_login'] 	= $this->server_ds_data['ssh_login'];
			$this->server_data['ssh_password'] 	= $this->server_ds_data['ssh_password'];
			$this->server_data['ssh_passwd'] 	= $this->server_ds_data['ssh_password'];
			$this->server_data['ssh_path'] 		= $this->server_ds_data['ssh_path'];
			
			$this->server_data['telnet_host']		= $this->server_ds_data['telnet_host'];
			$this->server_data['telnet_login'] 		= $this->server_ds_data['telnet_login'];
			$this->server_data['telnet_password'] 	= $this->server_ds_data['telnet_password'];
			$this->server_data['telnet_passwd'] 	= $this->server_ds_data['telnet_password'];
			$this->server_data['telnet_path'] 		= $this->server_ds_data['telnet_path'];
			
			$this->server_data['gdaemon_host']		= $this->server_ds_data['gdaemon_host'];
			$this->server_data['gdaemon_key'] 		= $this->server_ds_data['gdaemon_key'];

			$this->server_data['control_protocol'] 	= $this->server_ds_data['control_protocol'];
			$this->server_data['control_ip'] 		= $this->server_ds_data['control_ip'];
			$this->server_data['control_port'] 		= $this->server_ds_data['control_port'];
			$this->server_data['control_login'] 	= $this->server_ds_data['control_login'];
			$this->server_data['control_password'] 	= $this->server_ds_data['control_password'];
			
			$this->server_data['ds_modules_data'] 	= $this->server_ds_data['modules_data'];
			
			if (strtolower($this->server_data['os']) == 'windows') {
				$execfile = $this->_exec_file['windows'];
			} else {
				$execfile = $this->_exec_file['linux'];
			}
			
			$this->server_data['script_start'] 			= $execfile . ' ' . $this->server_ds_data['script_start'];
			$this->server_data['script_stop'] 			= $execfile . ' ' . $this->server_ds_data['script_stop'];
			$this->server_data['script_restart'] 		= $execfile . ' ' . $this->server_ds_data['script_restart'];
			$this->server_data['script_status'] 		= $execfile . ' ' . $this->server_ds_data['script_status'];
			$this->server_data['script_get_console'] 	= $execfile . ' ' . $this->server_ds_data['script_get_console'];
			$this->server_data['script_send_command'] 	= $execfile . ' ' . $this->server_ds_data['script_send_command'];
			
			/* Определение пути до скрипта и до steamcmd */
			switch ($this->server_data['control_protocol']) {
				case 'local':
					$this->server_data['script_path'] 	= $this->config->config['local_script_path'];
					$this->server_data['steamcmd_path'] = ($this->config->config['local_steamcmd_path']) ? $this->config->config['local_steamcmd_path'] : $this->config->config['local_script_path'];
					break;
					
				case 'telnet':
					$this->server_data['script_path'] 	= $this->server_ds_data['telnet_path'];
					$this->server_data['steamcmd_path'] = ($this->server_ds_data['steamcmd_path']) ? $this->server_ds_data['steamcmd_path'] : $this->server_ds_data['telnet_path'];
					break;

				default:
					// По умолчанию SSH
					$this->server_data['script_path'] 	= $this->server_ds_data['ssh_path'];
					$this->server_data['steamcmd_path'] = ($this->server_ds_data['steamcmd_path']) ? $this->server_ds_data['steamcmd_path'] : $this->server_ds_data['ssh_path'];
					break;
			}
		}
		
		// Получение сведений об игре
		if (!$no_get_game && $this->server_data['game']) {
			$where = array('code' => $this->server_data['game']);
			$this->games->get_games_list($where, 1);
			
			$this->server_game_data 				= $this->games->games_list['0'];
			$this->server_data['start_code'] 		= $this->server_game_data['start_code'];
			$this->server_data['game_name'] 		= $this->server_game_data['name'];
			$this->server_data['engine'] 			= $this->server_game_data['engine'];
			$this->server_data['engine_version'] 	= $this->server_game_data['engine_version'];
			
			$this->server_data['app_id'] 			= $this->server_game_data['app_id'];
			$this->server_data['app_set_config'] 	= $this->server_game_data['app_set_config'];
			
		} else {
			/* Информация об игре не найдена */
		}
		
		// Получение сведени о модификации
		if (!$no_get_gt && $this->server_data['game_type']) {
			$where = array('id' => $this->server_data['game_type']);
			$this->game_types->get_gametypes_list($where, 1);

			$this->server_data['mod_name'] 		= $this->game_types->game_types_list['0']['name'];
			$this->server_data['fast_rcon']		= $this->game_types->game_types_list['0']['fast_rcon'];
			$this->server_data['aliases_list'] 	= $this->game_types->game_types_list['0']['aliases'];
			$this->server_data['disk_size'] 	= $this->game_types->game_types_list['0']['disk_size'];
			
			$this->server_data['kick_cmd'] 		= $this->game_types->game_types_list['0']['kick_cmd'];
			$this->server_data['ban_cmd'] 		= $this->game_types->game_types_list['0']['ban_cmd'];
			$this->server_data['chname_cmd'] 	= $this->game_types->game_types_list['0']['chname_cmd'];
			$this->server_data['srestart_cmd'] 	= $this->game_types->game_types_list['0']['srestart_cmd'];
			$this->server_data['chmap_cmd'] 	= $this->game_types->game_types_list['0']['chmap_cmd'];
			$this->server_data['sendmsg_cmd'] 	= $this->game_types->game_types_list['0']['sendmsg_cmd'];
			$this->server_data['passwd_cmd'] 	= $this->game_types->game_types_list['0']['passwd_cmd'];
			
			$aliases_list 	= json_decode($this->server_data['aliases_list'], true);
			
			// Задаем дефолтные значения для пустых алиасов
			if (is_array($aliases_list)) {
				foreach ($aliases_list as $alias) {
					if (!isset($this->server_data['aliases_values'][$alias['alias']]) OR empty($this->server_data['aliases_values'][$alias['alias']])) {
						!isset($alias['default_value']) OR $this->server_data['aliases_values'][$alias['alias']] = $alias['default_value'];
					}
				}
			}
			
			$this->server_data['aliases'] = $this->server_data['aliases_values'];

		} else {
			/* Информация о модификации игры не найдена */
		}

		return $this->server_data;
	}
	
	//-----------------------------------------------------------
	
	/**
     * Получение данных сервера для шаблона
     * (вырезаны ненужные данные - пароли и пр.)
     * 
     *
    */
	function tpl_data()
    {
		$this->load->model('servers/games');
		$num = -1;
		
		$tpl_data = array();
		
		if (!isset($this->servers_list)) {
			$this->get_game_servers_list();
		}
		
		if (!empty($this->server_data)) {
			$num++;
			$tpl_data[$num]['server_id'] 			= $this->server_data['id'];
			$tpl_data[$num]['server_game_code'] 	= $this->server_data['game'];
			$tpl_data[$num]['server_game'] 			= $this->games->game_name_by_code($this->server_data['game']);
			$tpl_data[$num]['server_name'] 			= $this->server_data['name'];
			$tpl_data[$num]['server_ip'] 			= $this->server_data['server_ip'];
			$tpl_data[$num]['server_port'] 			= $this->server_data['server_port'];
			$tpl_data[$num]['server_query_port'] 	= $this->server_data['query_port'];
			$tpl_data[$num]['server_rcon_port'] 	= $this->server_data['rcon_port'];
		}
		
		foreach ($this->servers_list as $server_data) {
			$num++;
			
			$tpl_data[$num]['server_id'] 			= $server_data['id'];
			$tpl_data[$num]['server_game_code'] 	= $server_data['game'];
			$tpl_data[$num]['server_game'] 			= $this->games->game_name_by_code($server_data['game']);
			$tpl_data[$num]['server_name'] 			= $server_data['name'];
			$tpl_data[$num]['server_ip'] 			= $server_data['server_ip'];
			$tpl_data[$num]['server_port'] 			= $server_data['server_port'];
			$tpl_data[$num]['server_query_port'] 	= $server_data['query_port'];
			$tpl_data[$num]['server_rcon_port'] 	= $server_data['rcon_port'];
		}
		
		return $tpl_data;
	}

	// ----------------------------------------------------------------
    
    /**
     * Проверяет, существует ли сервер с данным id
     * 
     * @return bool
    */	
	function server_live($server_id)
	{
		$this->db->where('id', $server_id);
		return (bool)($this->db->count_all_results('servers') > 0);
	}
	
	// -----------------------------------------------------------------
	
	function select_fields($fields)
	{
		$this->_fields = $fields;
	}
	
	// ----------------------------------------------------------------
    
    /**
     * Проверяет, существует ли выделенный сервер с данным id
     * 
     * @return bool
    */	
	function ds_server_live($server_id)
	{
		$this->db->where('id', $server_id);
		return (bool)($this->db->count_all_results('dedicated_servers') > 0);
	}

	// ----------------------------------------------------------------
    
    /**
     * Проверяет статус сервера
     * 
     * @return bool
    */	
	function server_status($host = false, $port = false, $engine = false, $engine_version = false)
	{
		$this->load->library('query');
		$this->load->driver('rcon');
		
		if (!$host) {
			$host = $this->server_data['server_ip'];
		}
		
		if (!$port) {
			$port = $this->server_data['query_port'];
		}

		if (!$engine or !$engine_version) {
			$engine 			= $this->_get_engine();
			$engine_version 	= $engine[1];
			$engine 			= $engine[0];
		}

		$server['id'] = isset($this->server_data['id']) ? $this->server_data['id'] : 'get_status';
		$server['type'] = $engine;
		$server['host'] = $host . ':' . $port;
		$this->query->set_data($server);
		
		$this->query->set_data($server);

		$request = $this->query->get_status();
		$status = (bool)$request[ $server['id'] ];
		
		if (!$status && $engine == 'rust') {
			// Костыль для rust nosteam
			$this->rcon->set_variables(
				$this->server_data['server_ip'],
				$this->server_data['rcon_port'],
				$this->server_data['rcon'], 
				$this->server_data['engine'],
				$this->server_data['engine_version']
			);
			
			$status = $this->rcon->connect();
		}
		
		return $status;
	}

	//-----------------------------------------------------------
	
	/**
     * Получение списка игровых серверов
     * Функция аналогична get_servers_list за исключением того, что
     * ей можно задать любое условие, а не только id пользователей, 
     * которым принадлежит игровой сервер.
     * 
     * @param array 	условие для выборки
     * @param integer	лимит
     * @param integer 	offset
     * @param string	движок
     * @param integer	версия движка
     * 
     * @return array	
     * 
    */
    function get_game_servers_list($where = false, $limit = 10000, $offset = 0, $engine = false, $engine_version = false)
    {
		/* Если задан движок, то получаем список игр на этом движке,
		 * а после выбираем серверы только этих игр 
		 */
		if ($engine) {
			$this->db->where('engine', $engine);
			if ($engine_version) {$this->db->where('engine_version', $engine_version);}
			
			$this->db->select('code');
			$query = $this->db->get('games');
			
			$games_list = $query->result_array();
			
			foreach($games_list as &$game_data) {
				$games[] = $game_data['code'];
			}
			
			if(!empty($games)) {
				$this->db->where_in('game', $games);
			}
		}
		
		if (is_array($where)) {
			$this->db->where($where);
		}
		
		$this->db->limit($limit, $offset);
		$query = $this->db->get('servers');
		
		if ($query->num_rows > 0) {
			$this->servers_list = $query->result_array();
			return $this->servers_list;
			
		} else{ 
			return NULL;
		}
	}
	
	// ----------------------------------------------------------------
	
	/**
	 * Получение количества игровых серверов в зависимости от условия
	 * 
	 * @param array 	условие
	 * @return int
	 */
	function get_servers_count($where = array())
	{
		$this->db->where($where);
		return $this->db->count_all_results('servers');
	}
	
	// ----------------------------------------------------------------
	
	/**
	 * Получение списка файлов на сервере (удаленном или локальном)
	*/
	public function get_files_list($server_data = false, $dir = '', $file_time = false, $file_size = false)
	{
		$this->load->helper('ds');
		
		$dir = get_ds_file_path($this->servers->server_data) . '/' . $dir;
		$files_list = list_ds_files($dir, $this->servers->server_data);
	}

	// ----------------------------------------------------------------
    
    /**
     * Получает список файлов на локальном сервере
     * 
     * @param array - данные сервера
     * @param string - каталог на сервере
     * @param bool - получать время о последней модификации файла
     * @param bool - получать размер файла
     * 
     * @return array - возвращает список файлов с полным путем к файлу
     * 
    */
	function get_local_files($server_data, $dir, $file_time = false, $file_size = false)
	{
		$this->load->helper('ds');
		return list_ds_files($dir, $server_data, true);
	}
	
	
	// ----------------------------------------------------------------
    
    /**
     * Получает список файлов на удаленном сервере в указанной директории
     * 
     * @param array - данные сервера
     * @param string - каталог на сервере
     * @param bool - получать время о последней модификации файла
     * @param bool - получать размер файла
     * 
     * @return array - возвращает список файлов с полным путем к файлу
     * 
    */
	function get_remote_files($server_data, $dir, $file_time = false, $file_size = false)
	{
		$this->load->helper('ds');
		return list_ds_files($dir, $server_data, true);
	}


	// ----------------------------------------------------------------
	
    /**
     * ФУНКЦИЯ УСТАРЕЛА!
     * Получает список карт
     * 
    */
	function get_server_maps()
    {
		$this->load->helper('path');
		$this->load->helper('ds');
		$this->load->helper('date');
		
		if (strtolower($this->server_data['engine']) != 'goldsource' && strtolower($this->server_data['engine']) != 'source') {
			return;
		}

		/* Получаем список карт из базы (своеобразный кеш)*/
		$maps_cache = json_decode($this->server_data['maps_list'], true);
		
		/* Если списку не более суток */
		if($maps_cache && $maps_cache['time'] > now() - 86400){
			unset ($maps_cache['time']); // Удаляем time элемент
			$maps_cache['from_cache'] = 1;
			return $maps_cache;
		}
		
		// Если в пути пусто, то никаких карт
		if (empty($this->server_data['maps_path'])) {
			return;
		}
		
		$file_path = get_ds_file_path($this->server_data);
		$files_list = list_ds_files($file_path. '/' . $this->server_data['maps_path'], $this->server_data);

		/* Сортировка массива с файлами по возрастанию
		 * 
		 * Применена пользовательская сортировка по функции uasort_asc
		 * которая чуть ниже. Сортировка происходит по file_name 
		 * в массиве
		*/
		
		if (!empty($files_list)) {
			asort($files_list);
			
			$maps = array();
			
			/* Перебор карт, и удаление расширения файла */	
			foreach ($files_list as $file) {
				$extension = pathinfo($file, PATHINFO_EXTENSION);
				
				if ($extension != 'bsp') {
					continue;
				}
				
				$maps[]['map_name'] = str_replace('.bsp', '', basename($file));
			}
			
			return $maps;
			
		} else {
			return NULL;
		}
	}
	
	// ----------------------------------------------------------------
	 
    /**
     * Сортировка массива по возрастанию
    */
	function uasort_asc($a, $b) 
	{
		if ($a['file_name'] === $b['file_name']) return 0;
		return $a['file_name'] > $b['file_name'] ? 1 : -1;
	}
	
	// ----------------------------------------------------------------
    
    /**
     * Сортировка массива по убыванию
    */
	function uasort_desc($a, $b) 
	{
		if ($a['file_name'] === $b['file_name']) return 0;
		return $a['file_name'] < $b['file_name'] ? 1 : -1;
	}
	
	// ----------------------------------------------------------------
	 
    /**
     * Читает содержимое файла с локального сервера
     * 
     * @param str
     * @return str
    */
	function read_local_file($file)
	{
		$server_data = empty($server_data) ? $this->server_data : $server_data;
		$this->load->helper('ds');
		return read_ds_file($file, $server_data);
	}
	
	// ----------------------------------------------------------------
	 
    /**
     * Читает содержимое файла
     * 
     * @param string 	$file расположение файла без script_path и dir
     * @param array		$server_data массив с данными сервера
     * @return string
    */
	public function read_file($file, $server_data = array()) 
	{
		$server_data = empty($server_data) ? $this->server_data : $server_data;
		$this->load->helper('ds');
		return read_ds_file($file, $server_data);
	}
	
	// ----------------------------------------------------------------
	 
    /**
     * Читает содержимое файла с удаленного сервера
     * 
     * @param str
     * @return str
     * 
    */
	function read_remote_file($file, $server_data = array())
	{
		$server_data = empty($server_data) ? $this->server_data : $server_data;
		$this->load->helper('ds');
		return read_ds_file($file, $server_data);
	}
	
	// ----------------------------------------------------------------
	
    /**
     * Смена rcon пароля серверу
     * 
     * @param string	$new_rcon новый RCON пароль
     * @pararm array	$server_data данные сервера
     * @param bool		$update_db обновлять данные БД
     * 
     * @return bool
    */
    function change_rcon($new_rcon, $server_data = false, $update_db = false) 
    {
		$this->load->helper('patterns_helper');
		$this->load->driver('rcon');
		
		if($server_data) {
			$this->server_data = $server_data;
		}
		
		$this->rcon->set_variables(
								$this->server_data['server_ip'],
								$this->server_data['server_port'],
								$this->server_data['rcon'], 
								$this->servers->server_data['engine'],
								$this->servers->server_data['engine_version']
		);
		
		try {
			$this->rcon->change_rcon($new_rcon);

			if ($update_db) {
				$sql_data = array('rcon' => $new_rcon);
				$this->edit_game_server($this->server_data['id'], $sql_data);
			}
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
	
	// ----------------------------------------------------------------
	
    /**
     * Получение настроек сервера
     * 
     * @param int - id сервера
     * @param int - id пользователя
     * 
     * @return array
    */
    function get_server_settings($server_id, $user_id = false)
    {
		if(!$user_id) {
            $where = array('server_id' => $server_id);
        } else {
            $where = array('server_id' => $server_id, 'user_id' => $user_id);
        }
        
        $query = $this->db->get_where('settings', $where);
        $server_settings = array();
        foreach ($query->result_array() as $settings) {
            if(array_key_exists($settings['sett_id'], $this->all_settings)) {
                $server_settings[$settings['sett_id']] = $settings['value'];
            }
        }
        
        // Заполнение пустых значений
        foreach ($this->all_settings as $key => $value) {
            if(!array_key_exists($key, $server_settings)) {
                $server_settings[$key] = 0;
            }
        }
        
        $this->server_settings = $server_settings;
        
        return $server_settings;
	}
	
	// ----------------------------------------------------------------

    /**
     * Запись настроек
     * 
     * @return bool
    */
    function set_server_settings($sett_id, $value, $server_id, $user_id = false)
    {
        $where = array('sett_id' => $sett_id,
						'server_id' => $server_id);
						
		if($user_id) {
			$where['user_id'] = $user_id;
		}
        
        $query = $this->db->get_where('settings', $where);
        
        $data = array(
            'sett_id' 		=> $sett_id,
            'server_id' 	=> $server_id,
            'value' 		=> $value
        );
        
        if($user_id) {
			$data['user_id'] = $user_id;
		}
        
        $this->db->where('sett_id', $sett_id);
        $this->db->where('server_id', $server_id);
            
        if($query->num_rows > 0){
           /* Если привилегия уже есть в базе данных, то обновляем */
           if($this->db->update('settings', $data)){
                return true;
            }else{
                return false;
            }
            
        }else{
			/* Привилегии нет в базе данных, создаем новую строку */
			if($this->db->insert('settings', $data)){
                return true;
            }else{
                return false;
            }
		}

            
    }
}


/* End of file servers.php */
/* Location: ./application/models/servers.php */
