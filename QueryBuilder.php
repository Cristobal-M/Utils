<?php
class QueryBuilder{
    const OR_COND = ' OR ';
    const AND_COND = ' AND ';
    private $select = '';
    private $from = '';
    private $where = '';
    private $join = '';
    
    private $pdo = null;
    private $mysqli = null;
    
    private $parenthesesToOpen = 0;
    private $parenthesesToClose = 0;
    
    private $lastParenthesesSegment = null;
    
    public function setPDO(&$pdo){
        $this->pdo = $pdo;
        
    }
    
    public function setMYSQLI(&$mysqli){
        $this->mysqli = $mysqli;
        
    }
    
    public function setTable($table){
        $this->from = ' FROM ' .( is_array($table) ? implode(', ', $table) : $table). ' ';
        return $this;
    }
    
    //Inicia una clausula (SELECT, WHERE ...) o inserta un condicional OR o AND 
    //Tambien abre un parentesis si se ha establecido
    public function initClause($clause, $word, $cond = ''){
        $res = true;
        if(strlen($this->$clause) > 0){
            $this->$clause.=$cond;
            $res = false;
        } else{
            $this->$clause = $word;
            $res = true;
        }
        $this->checkParentheses($this->$clause, 'open');
        return $res;
    }
    
    public function select($columns = null){
        $first = $this->initClause('select', ' SELECT ', ', ');
        $this->select .= ($columns === null || count($columns) === 0)? ' * ' :  implode(', ', $columns) ;
        return $this;
    }
    
    //Funcion base para la clausula where: 
    private function _where($column, $comp, $value, $cond = self::AND_COND){
        $this->initClause('where', ' WHERE ', $cond);
        $this->where .= " $column $comp {$this->escape($value)} ";
        return $this;
    }
    
    private function _whereArray($data, $options = array(), $cond = self::AND_COND){
        foreach($data as $key => $value){
            if( isset($options[$key]) ){
                $this->_where($key, $options[$key][1], $value, $options[$key][0] === 'or' ? self::OR_COND : self::AND_COND);
            } else if( in_array($key, $options) ){
                $this->_where($key, '=', $value, $cond);
            }
        }
        return $this;
    }
    
    private function _join($type = ' INNER ', $table, $condition){
        $this->join .= "$type JOIN $table ON $condition ";
        return $this;
    }
    
    private function checkParentheses(&$text, $type){
        if($type === 'open' && $this->parenthesesToOpen > 0){
            $text .= str_repeat ('(', $this->parenthesesToOpen);
            $this->parenthesesToOpen = 0;
            $this->lastParenthesesSegment = &$text;
        }
        else if($type === 'close' && $this->parenthesesToClose > 0){
            $text .= str_repeat (')', $this->parenthesesToClose);
            $this->parenthesesToClose = 0;
        }
    }
    
    private function escape($arg){
        if($this->pdo !== null){
            return $this->pdo->quote($arg);;
        }
        if($this->mysqli !== null){
            return $this->mysqli->real_escape_string($arg);;
        }
        
        return is_numeric($arg) ? $arg : "'$arg'";
    }
    private function _between($column, $min, $max, $cond = self::AND_COND){
        $this->initClause('where', ' WHERE ', $cond);
        
        $this->where .= " $column BETWEEN {$this->escape($min)} AND {$this->escape($max)}";
        return $this;
    }
    
    public function __call($method, $args) {
        $argCount = count($args);
        $match = array();
        preg_match("/(or|and)?[Ww]here(Array)?/", $method, $match);
        //Nos aseguramos de que la expresion regular haya encontrado algo y que haya suficientes argumentos
        if( count($match) && $argCount >= 2) {
            //Si es un or o and 
            $cond = ( isset($match[1]) && $match[1] === 'or') ? self::OR_COND : self::AND_COND;
            //En caso de whereArray
            if( $argCount == 2 && isset($match[2]) ){
                return $this->_whereArray($args[0], $args[1], $cond);
            }
            
            if( $argCount == 2) {
                return $this->_where($args[0], '=', $args[1], $cond);
            }
            else if( $argCount == 3) {
                return $this->_where($args[0], $args[1], $args[2], $cond);
            }
        }
        preg_match("/(or|and)?[Bb]etween?/", $method, $match);
        //Nos aseguramos de que la expresion regular haya encontrado algo y que haya suficientes argumentos
        if( count($match) && $argCount === 3) {
            //Si es un or o and 
            $cond = ( isset($match[1]) && $match[1] === 'or') ? self::OR_COND : self::AND_COND;
            return $this->_between($args[0], $args[1], $args[2], $cond);
        }
        
        preg_match("/(inner|left|right|full)Join?/", $method, $match);
        //Nos aseguramos de que la expresion regular haya encontrado algo y que haya suficientes argumentos
        if( count($match) && isset($match[1]) && $argCount === 2) {
            return $this->_join(' ' . strtoupper($match[1]) . ' ', $args[0], $args[1]);
        }
        
   }
    
    public function openParentheses($num = 1){
        $this->parenthesesToOpen += $num;
        return $this;
    }
    public function closeParentheses($num = 1){
        $this->parenthesesToClose += $num;
        $this->checkParentheses($this->lastParenthesesSegment, 'close');
        return $this;
    }
   
   public function getSql(){
       if($this->select === '') $this->select();
       return $this->select . $this->from . $this->join . $this->where;
    }
    
}

$query = new QueryBuilder();
echo $query->setTable(['prueba', 'test'])->select(['hola', 'piuoi'])->select(['dddd'])//->openParentheses()->between('param2', 50, 60)->closeParentheses()->openParentheses()//->where('col1', 'hola')->orWhere('col2', 34)->andWhere('col342', '!=', 90)
->orWhereArray(['param1' => 'value1', 'param2' => 'value2', 'param3' => 'value3', 'param4' => 'value5'], ['param2', 'param4', 'param1'])->closeParentheses()
//->leftJoin('unaTabla', 'unaTabla.aa = prueba.aa')->innerJoin('unaTabla', 'unaTabla.aa = prueba.aa')
->getSql() . "\n";
