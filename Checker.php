<?php

class RequestChecker{
    //Un array, se entiende que cada elemento debe validarse en funcion de lo que se defina
    const TYPE_ITERABLE = 'iterable';
    const TYPE_OBJECT = 'object';
    const PROPERTY_TYPES = ['int', 'string', 'text'];
    const VALIDATION_DEFAULT = ['nullable'=> false, 'database' => false, 'type' => null, 'checker' => null];
    const SPACES = array("\r", "\n", " ", "\t");

    //public const TYPES = [self::TYPE_INT, self::TYPE_STRING, self::TYPE_ARRAY];
    //Contendra informacion sobre las propiedades
    private $properties;
    //El tipo del objeto que comprobaremos, puede ser un objeto normal (o array, se acceden de igual manera)
    // o un array, en ese caso se itera cada elemento y se comprueba a partir de las
    //propiedades definidad
    private $type;
    //Cambiara entre 'validationBefore' y 'validationAfter'
    private $currentPropertyName;
    //La propiedad que actualmente estamos "configurando"
    private $currentPropertySetting;
    //Los mensajes de error que se iran produciendo
    private $errors;
    //El objeto que estamos comprobando
    private $data;

    function __construct($type = self::TYPE_OBJECT){
        $this->currentPropertySetting = null;
        $this->errors = '';
        $this->properties = array('validationBefore' =>[], 'fromJSON' => [], 'validationAfter'=>[]);
        $this->type = $type;
    }

    public static function Create($type = self::TYPE_OBJECT){
      return new self($type);
    }

    public function setType($type){
        $this->type = $type;
    }

    public function getType(){
        return $this->type;
    }

    public function getData(){
        return $this->data;
    }

    public function setProperty($name){
        $this->currentPropertyName = $name;
        $this->properties['validationBefore'][$this->currentPropertyName] = self::VALIDATION_DEFAULT;
        unset($this->currentPropertySetting);
        $this->currentPropertyName = $name;
        $this->currentPropertySetting = &$this->properties['validationBefore'][$name];

        return $this;
    }

    public function fromJSON($toArray = true){
        $this->properties['fromJSON'][$this->currentPropertyName] = $toArray;
        return $this;
    }

    public function required($value = true){
        $this->currentPropertySetting['required'] = $value;
        return $this;
    }

    public function nullable($value = true){
        $this->currentPropertySetting['nullable'] = $value;
        return $this;
    }

    public function database($value = true){
        $this->currentPropertySetting['database'] = $value;
        return $this;
    }

    public function type($type = null){
        if( $type === null || in_array($type, self::PROPERTY_TYPES) ){
          $this->currentPropertySetting['type'] = $type;
        }
        else{
          throw new \Exception("Argument for function type with value $type is invalid");
        }

        return $this;
    }

    public function before(){
        unset($this->currentPropertySetting);
        $this->currentPropertySetting = &$this->properties['validationBefore'][$this->currentPropertyName];
        return $this;
    }

    public function after(){
        unset($this->currentPropertySetting);
        $this->currentPropertySetting = &$this->properties['validationAfter'][$this->currentPropertyName];
        if( !isset($this->currentPropertySetting) ){
            $this->currentPropertySetting =  self::VALIDATION_DEFAULT;
        }

        return $this;
    }

    public function checker($checkObj){
        if( ! $checkObj instanceof self ) throw new Exception('Argument for function checker is not an instance of ' . get_class());
        $this->currentPropertySetting['checker'] = $checkObj;
        return $this;
    }

    private function addError($name, $message){
        $this->errors.= "$name: $message\n";
    }

    public function getErrors($linePrefix = null){
        if($linePrefix){
            return str_replace("\n", "\n$linePrefix", $this->errors);
        }
        return $this->errors;
    }

    private function removeSpaces($value){
        $value = str_replace("\xEF\xBB\xBF", "", $value);
        return str_replace(self::SPACES, '', $value);//preg_replace('/\s+/', ' ', $value);
    }

    private function escape($value){
        return $value;
    }

    private function checkRequired($prop, $options){
        $nullable = $options[$prop]['nullable'];
        $forDatabase = $options[$prop]['database'];

        if( isset($this->data[$prop]) ){
            $empty = $this->removeSpaces($this->data[$prop]);
            //echo "-------------\n$empty\n";
            if(!$nullable && $empty === ''){
                $this->addError($prop, "The property $prop is required but is empty and is not nullable");
                return;
            }
            if( $nullable && $empty === ''){

                if($forDatabase)
                    $this->data[$prop] = 'NULL';
                else
                    $this->data[$prop] = '';
                return;
            }
        }
        else{
            $this->addError($prop, "The property $prop is required but has not been set");
        }
    }

    private function checkType($prop, $options){

        if( !isset($this->data[$prop]) ) return;
        $type = $options[$prop]['type'];
        $forDatabase = $options[$prop]['database'];
        switch ($type){
            case 'int':
                if( is_numeric($this->data[$prop]) ){
                    $this->data[$prop] = (int) $this->data[$prop];
                }
                else{
                    $this->addError($prop, "The property $prop is not numeric");
                }
                break;
            case 'string':
                $value = (string) $this->data[$prop];
                $empty = $this->removeSpaces($value);
                if($forDatabase){
                    if($empty === '')
                      $value = '';
                    else
                      $value = "'{$this->escape($value)}'";
                }
                $this->data[$prop] = $value;
                break;

            case 'text':
                $value = (string) $this->data[$prop];
                str_replace("\xEF\xBB\xBF", "", $value);
                if($forDatabase){
                    $value = "'{$this->escape($value)}'";
                }
                $this->data[$prop] = $value;
        }
    }

    private function checkSubChecker($prop, $options){
        $checker = $options[$prop]['checker'];
        if($checker === null) return;

        if( !empty($this->data[$prop]) && !$checker->check($this->data[$prop]) ){
            $this->addError('Checking', "The property $prop does not comply with:\n\t " . $checker->getErrors("\t") );
            //Es posible que haya habido alguna transformacion
        }
        $this->data[$prop] = $checker->getData();
        $checker->clearErrors();
    }

    private function checkObject(){

        foreach ($this->properties['validationBefore'] as $prop => $options) {
            $this->checkRequired($prop, $this->properties['validationBefore']);
            $this->checkType($prop, $this->properties['validationBefore']);
            $this->checkSubChecker($prop, $this->properties['validationBefore']);
        }

        foreach ($this->properties['fromJSON'] as $prop => $option) {
            $this->data[$prop] = json_decode($this->data[$prop], $option);
        }

        foreach ($this->properties['validationAfter'] as $prop => $options) {
            $this->checkRequired($prop, $this->properties['validationAfter']);
            $this->checkType($prop, $this->properties['validationAfter']);
            $this->checkSubChecker($prop, $this->properties['validationAfter']);
        }

        return $this->errors === '';
    }

    private function checkArray(){
        //Vamos a establecer cada elemento del array (actualmente $this->data) con $this->data
        //y reemplazar el resultado en el array que al final volvera a ser $this->data
        $dataArray = $this->data;
        $result = true;
        foreach ($dataArray as $key => $data) {
            $this->data = $data;
            if(!$this->checkObject() ){
                $result = false;
            }
            $dataArray[$key] = $this->data;
        }
        $this->data = $dataArray;
        return $result;
    }

    public function check($data, $throwEx = false){
        $this->data = $data;
        $res = false;
        switch ($this->type) {
            case self::TYPE_ITERABLE:
                $res = $this->checkArray();
                break;
            case self::TYPE_OBJECT:
                $res = $this->checkObject();
        }
        if(!$res && $throwEx){
          throw new \Exception($this->errors);
        }
        return $res;
    }

    public function clearErrors(){
        $this->errors = '';
    }
}

$checkerPrueba = RequestChecker::Create()->setProperty('hello')->type('string')->database();

$checker = new RequestChecker(RequestChecker::TYPE_ITERABLE);
$checker->setProperty('prueba')->required()->fromJSON(true)->after()->checker($checkerPrueba);
$checker->setProperty('cosa')->type('string')->database();

$test = array(array('cosa' => "\n", 'prueba' => '{"hello": "world"}'), array('cosa' => 90, 'prueba' => '{"hello": "world"}'));

echo "##ANTES\n";
var_dump($test);
echo "##VALIDACION Y CONVERSION\n";

if( !$checker->check($test) ){
    var_dump($checker->getErrors());
}
echo "##DESPUES\n";
var_dump($checker->getData());
