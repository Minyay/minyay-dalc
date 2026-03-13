<?php
	namespace Minyay\Dalc;

	class DalcMySQLi {

		private $is_debug = false;
		private $timing_enabled = false;
		private $table_name;
		private $insert_columns;
		private $insert_values;
		private $insert_rows = [];
		private $columns_locked = false;
		private $order_by;
		private $group_by;
		private $having;
		private $where_clauses;
		private $set_clause;
		private $db_spec;
		private $mysqli;

		// Safety limit: max rows to fetch before aborting to prevent OOM.
		private const MAX_ROWS_SAFETY = 50000;
		private $skip_row_limit = false;

		/**
		 * @param object $db_spec  Database config with properties: db_host, db_user, db_password, db_name, db_auth (optional: 'iam'), db_ssl_ca (optional)
		 * @param bool   $debug    Enable debug mode (SQL logging + timing)
		 */
		public function __construct($db_spec, $debug = false){
			$this->db_spec = $db_spec;
			if($debug){
				$this->is_debug = true;
				$this->timing_enabled = true;
			}
		}

		public function __destruct(){
		}

		public function connect(){

			$this->mysqli = mysqli_init();

			if (!$this->mysqli) {
				die('mysqli_init failed');
			}

			$password = $this->db_spec->db_password ?? '';

			// IAM DB authentication: generate token from instance role
			if(isset($this->db_spec->db_auth) && $this->db_spec->db_auth === 'iam'){
				$provider = \Aws\Credentials\CredentialProvider::instanceProfile();
				$tokenGenerator = new \Aws\Rds\AuthTokenGenerator($provider);
				$password = $tokenGenerator->createToken(
					$this->db_spec->db_host . ':3306',
					getenv('AWS_DEFAULT_REGION') ?: 'eu-central-1',
					$this->db_spec->db_user
				);

				$this->mysqli->ssl_set(null, null, null, null, null);
				$this->mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
			}

			$connect_flags = 0;
			if(isset($this->db_spec->db_auth) && $this->db_spec->db_auth === 'iam'){
				$connect_flags = MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
			}

			if (!$this->mysqli->real_connect($this->db_spec->db_host, $this->db_spec->db_user, $password, $this->db_spec->db_name ?? 'minyay', 3306, null, $connect_flags)) {
				die('Connect Error ('.$this->mysqli->errno.') '. $this->mysqli->error);
			}

			if (!$this->mysqli->set_charset('utf8')) {
				die('Charset Error ('.$this->mysqli->errno.') '. $this->mysqli->error);
			}
		}

		public function runsql($sql){
			return $this->run($sql);
		}

		public function activate_test(){
			$this->is_debug = true;
		}

		public function deactivate_test(){
			$this->is_debug = false;
		}

		public function clear(){
			unset($this->table_name);
			unset($this->insert_columns);
			unset($this->insert_values);
			$this->insert_rows = [];
			$this->columns_locked = false;
			unset($this->order_by);
			unset($this->group_by);
			unset($this->having);
			unset($this->where_clauses);
			unset($this->set_clause);
			unset($this->affected_rows);
		}

		public function table($table_name){
			$this->table_name = $this->secure($table_name);
		}

		public function add($column, $value){
			$column = $this->secure($column);
			$value = $this->secure($value);

			if(!$this->columns_locked){
				if(isset($this->insert_columns)){$this->insert_columns .= ', '.$column;}else{$this->insert_columns = $column;}
			}
			if(isset($this->insert_values)){$this->insert_values .= ', \''.$value.'\'';}else{$this->insert_values = '\''.$value.'\'';}
		}

		// Queue the current row for a multi-row insert.
		// Call add() for each column, then add_row() to queue it. Repeat for each row.
		// Finally call insert() to execute all queued rows in a single INSERT.
		public function add_row(){
			if(isset($this->insert_values)){
				$this->insert_rows[] = '('.$this->insert_values.')';
				unset($this->insert_values);
				$this->columns_locked = true;
			}
		}

		public function insert($pignore = false){
			$ignore = $pignore ? 'IGNORE' : '';
			if(!empty($this->insert_rows)){
				// Include current row if add_row() wasn't called for the last one
				if(isset($this->insert_values)){
					$this->insert_rows[] = '('.$this->insert_values.')';
				}
				$sql = 'INSERT '.$ignore.' INTO '.$this->table_name.' ('.$this->insert_columns.') VALUES '.implode(', ', $this->insert_rows);
				return $this->run($sql);
			}

			$sql = 'INSERT '.$ignore.' INTO '.$this->table_name.' ('.$this->insert_columns.') VALUES ('.$this->insert_values.')';
			return $this->run($sql);
		}

		// Disable the OOM guard for the next get() call.
		// Resets automatically after the query executes.
		public function no_row_limit(){
			$this->skip_row_limit = true;
		}

		public function get($columns, $limit){
			$limit = $this->secure($limit);

			if($limit){$ss_limit = ' LIMIT '.$limit;} else {$ss_limit = '';}

			$where = $this->create_where_clause();

			if(!isset($this->order_by)){
				$this->order_by = '';
			}

			if(!isset($this->group_by)){
				$this->group_by = '';
			}

			if(!isset($this->having)){
				$this->having = '';
			}

			$sql = 'SELECT '.$columns.' FROM '.$this->table_name.$where.$this->group_by.$this->having.$this->order_by.$ss_limit;
			$result = $this->run($sql);

			$list = array();

			if($result->status){

				$bypass_limit = $this->skip_row_limit;
				$this->skip_row_limit = false;

				if(!$bypass_limit && $result->nor > self::MAX_ROWS_SAFETY){
					if(function_exists('\Sentry\captureMessage')){
						\Sentry\captureMessage(
							'DalcMySQLi: Query returned ' . $result->nor . ' rows (limit: ' . self::MAX_ROWS_SAFETY . '). '
							. 'Table: ' . ($this->table_name ?? 'unknown') . '. Query truncated to prevent OOM.',
							\Sentry\Severity::warning()
						);
					}
					error_log('DalcMySQLi OOM guard: query on table [' . ($this->table_name ?? 'unknown') . '] returned ' . $result->nor . ' rows, truncating to ' . self::MAX_ROWS_SAFETY);
				}

				$safety_limit = $bypass_limit ? $result->nor : min($result->nor, self::MAX_ROWS_SAFETY);

				$i = 0;
				while ($i < $safety_limit && ($row = mysqli_fetch_object($result->set))){
					$list[$i] = $row;
					$i++;
				}

				// Free the MySQL result set immediately to release memory.
				mysqli_free_result($result->set);
			}

			$result->set = $list;
			return $result;
		}

		private function create_where_clause(){

			$final_where = '';
			$noc = 0;

			if(isset($this->where_clauses)){
				$noc = count($this->where_clauses);
			}

			if($noc>0){
				for($i=0; $i<$noc; $i++){
					if($this->where_clauses[$i]['cluster'] != ''){
						$clauses[$this->where_clauses[$i]['cluster']][] = $this->where_clauses[$i]['statement'];
					}
				}

				for($i=0; $i<$noc; $i++){
					if($this->where_clauses[$i]['cluster'] == ''){
						$clauses[][] = $this->where_clauses[$i]['statement'];
					}
				}

				$final_where = ' WHERE';

				foreach($clauses as $clause){
					$nos = count($clause);
					if($nos == 1){
						$final_where .=	' '.$clause[0];
					}
					else{
						$final_where .=	' (';
						for($j=0; $j<$nos; $j++){
							$final_where .=	' '.$clause[$j].' OR';
						}
						$final_where = substr($final_where, 0, -3);
						$final_where .=	')';
					}
					$final_where .=	' AND';
				}

				$final_where = substr($final_where, 0, -4);
			}

			return $final_where;
		}

		public function where($column_name, $operator, $value, $cluster=''){

			if($operator == 'IN' || $operator == 'NOT IN'){
				if(is_array($value)){
					$cnt = count($value);
				}
				else{
					$cnt = 0;
				}

				// Handle empty array: IN () is invalid SQL
				if($cnt == 0){
					if($operator == 'IN'){
						$this->where_clauses[] = array('statement' => '1=0', 'cluster' => $cluster);
					}
					// For NOT IN with empty array, skip the clause (all rows match)
					return;
				}

				$str = '';
				for($i=0; $i<$cnt; $i++){
					// Don't quote numeric values - use them directly for proper SQL
					if(is_numeric($value[$i])){
						$str .= intval($value[$i]).',';
					} else {
						$str .= '"'.$this->secure($value[$i]).'",';
					}
				}
				$str = '('.substr($str, 0, -1).')';

				$this->where_clauses[] = array('statement' => $column_name.' '.$this->secure($operator).' '.$str, 'cluster' => $cluster);
			}
			elseif($operator == '<' || $operator == '>'){
				$this->where_clauses[] = array('statement' => $column_name.' '.$operator.' \''.$this->secure($value).'\'', 'cluster' => $cluster);
			}
			elseif($operator == 'IS' || $operator == 'IS NOT'){
				$this->where_clauses[] = array('statement' => $column_name.' '.$this->secure($operator).' '.$this->secure($value), 'cluster' => $cluster);
			}
			elseif($column_name && $operator){
				$this->where_clauses[] = array('statement' => $column_name.' '.$this->secure($operator).' \''.$this->secure($value).'\'', 'cluster' => $cluster);
			}
		}

		public function update(){
			$where = $this->create_where_clause();
			$sql = 'UPDATE '.$this->table_name.' SET '.$this->set_clause.$where;
			return $this->run($sql);
		}

		public function delete(){
			$where = $this->create_where_clause();
			if($where){
				$sql = 'DELETE FROM '.$this->table_name.$where;
				return $this->run($sql);
			}
		}

		private function run($sql){

			$or = new \stdClass();

			if($this->is_debug){
				$or->sql = $sql;
			}
			else{
				$or->sql = 'Debug mode is off';
			}

			$msc = microtime(true);
			$resultset = $this->mysqli->query($sql);
			if($this->timing_enabled){
				$or->timing = microtime(true) - $msc;
			}
			else{
				$or->timing = 'Timing is disabled';
			}

			if($resultset === false){
				$or->status = false;
				$or->error = $this->mysqli->error;
				$or->eno = $this->mysqli->errno;
			}
			elseif($resultset === true){
				$or->status  = true;
				$or->id = $this->mysqli->insert_id;
				$or->noa = $this->mysqli->affected_rows;
			}
			else{
				$or->status  = true;
				$or->set = $resultset;
				$or->nor = $resultset->num_rows;
			}

			return $or;
		}

		private function secure($var){
			$var = $this->mysqli->real_escape_string($var);
			$var = str_replace('<script', '<', $var);
			return $var;
		}

		public function error(){
			return $this->mysqli->error;
		}

		public function pick_random(){
			$this->order_by = ' ORDER BY RAND()';
		}

		public function orderby($column_name, $order_param = ''){
			if($column_name){
				$column_name = $this->secure($column_name);
				$order_param = $this->secure($order_param);

				if($order_param == 'ASC' || $order_param == 'DESC' || $order_param == ''){
					if(!isset($this->order_by)){
						$this->order_by = ' ORDER BY '.$column_name.' '.$order_param;
					}
					else{
						$this->order_by .= ', '.$column_name.' '.$order_param;
					}
				}
			}
		}

		public function groupby($column_name){
			if($column_name){
				if(!isset($this->group_by)){
					$this->group_by = ' GROUP BY '.$column_name;
				}
				else{
					$this->group_by .= ', '.$column_name;
				}
			}
		}

		public function having($condition){
			if($condition){
				if(!isset($this->having)){
					$this->having = ' HAVING '.$condition;
				}
			}
		}

		public function set($column_name, $value){
			if(isset($this->set_clause)){
				$this->set_clause .= ', ';
			} else {
				$this->set_clause = '';
			}

			$column_name = $this->secure($column_name);
			$value = $this->secure($value);

			$this->set_clause .= ' '.$column_name.'=\''.$value.'\'';
		}

		public function begin(){
			$this->mysqli->query('SET AUTOCOMMIT = 0');
			$this->mysqli->query('START TRANSACTION');
		}

		public function rollback(){
			$this->mysqli->query('ROLLBACK');
			$this->mysqli->query('SET AUTOCOMMIT = 1');
		}

		public function commit(){
			$this->mysqli->query('COMMIT');
			$this->mysqli->query('SET AUTOCOMMIT = 1');
		}

		public function optimize($table_name){
			$this->run('OPTIMIZE TABLE '.$table_name);
		}

	}
