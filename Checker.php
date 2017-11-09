<?php

class RequestChecker{
    //Un array, se entiende que cada elemento debe validarse en funcion de lo que se defina
    const TYPE_ITERABLE = 'iterable';
    const TYPE_OBJECT = 'object';
    const PROPERTY_TYPES = ['int', 'string', 'text'];
    const VALIDATION_DEFAULT = ['nullable'=> false, 'database' => false, 'type' => null, 'checker' => null];

    //public const TYPES = [self::TYPE_INT, self::TYPE_STRING, self::TYPE_ARRAY];
    //Contendra informacion sobre las propiedades
    private $properties;
    //El tipo del objeto que comprobaremos, puede ser un objeto normal (o array, se acceden de igual manera)
    // o un array, en ese caso se itera cada elemento y se comprueba a partir de las
    //propiedades definidad
    private $type;
    //Cambiara entre 'validationBefore' y 'validationAfter'
    private $currentPropValSetting;
    //La propiedad que actualmente estamos "configurando"
    private $currentPropertySetting;
    //Los mensajes de error que se iran produciendo
    private $errors;
    //El objeto que estamos comprobando
    private $data;

    function __construct($type = self::TYPE_OBJECT){
        $this->currentPropertySetting = null;
        //Por defecto empezaremos defineindo las validaciones anteriores a la
        //transformacion, si es que ocurriera
        $this->currentPropValSetting = 'validationBefore';
        $this->errors = array();
        $this->properties = array();
        $this->type = $type;
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
        $this->before();
        $this->properties[$this->currentPropValSetting][$name] = self::VALIDATION_DEFAULT;
        //unset($this->currentPropertySetting);
        $this->currentPropertySetting = $name;


        return $this;
    }

    public function fromJSON($toArray = true){
        $this->properties['fromJSON'][$this->currentPropertySetting] = $toArray;
        return $this;
    }

    public function required($value = true){
        $this->properties[$this->currentPropValSetting][$this->currentPropertySetting]['required'] = $value;
        return $this;
    }

    public function nullable($value = true){
        $this->properties[$this->currentPropValSetting][$this->currentPropertySetting]['nullable'] = $value;
        return $this;
    }

    public function type($type = null){
        $this->properties[$this->currentPropValSetting][$this->currentPropertySetting]['type'] = $type;
        return $this;
    }

    public function before(){
        $this->currentPropValSetting = 'validationBefore';
        return $this;
    }

    public function after(){
        $this->currentPropValSetting = 'validationAfter';
        $options = &$this->properties[$this->currentPropValSetting][$this->currentPropertySetting];
        if( !isset($options) ){
            $options =  self::VALIDATION_DEFAULT;
        }

        return $this;
    }

    public function checker($checkObj){
        if( ! $checkObj instanceof self ) throw new Exception('Argument is not of a instance of RequestChecker');
        $this->properties[$this->currentPropValSetting][$this->currentPropertySetting]['checker'] = $checkObj;
        return $this;
    }

    private function addError($name, $message){
        if( isset($this->errors[$name]) ){
            array_push($this->errors[$name], $message);
            return;
        }
        $this->errors[$name] = [$message];
    }
    public function getErrors(){
        return $this->errors;
    }


    private function makeEmptyIfPossible($value){
        $value = str_replace("\xEF\xBB\xBF", "", $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value;
    }

    private function escape($value){
        return $value;
    }

    private function checkRequired($prop, $options){
        $nullable = $options[$prop]['nullable'];
        $forDatabase = $options[$prop]['database'];

        if( isset($this->data[$prop]) ){
            $empty = $this->makeEmptyIfPossible($this->data[$prop]);
            echo "-------------\n$empty\n";
            if(!$nullable && $empty === ''){
                $this->addError($prop, "The property $prop with value '{$this->data[$prop]}' is required but not nullable");
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
                    $this->addError($prop, "The property $prop is not of type: $type");
                }
                break;
            case 'string':
                $value = (string) $this->data[$prop];
                if($forDatabase){
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
            $this->addError('Checking', "The property $prop does not comply with " . json_encode($checker->getErrors()) );
            //Es posible que haya habido alguna transformacion
            $this->data[$prop] = $checker->getData();
            $checker->clearErrors();
        }
    }

    private function checkObject(){

        foreach ($this->properties['validationBefore'] as $prop => $options) {
            $this->checkType($prop, $this->properties['validationBefore']);
            $this->checkRequired($prop, $this->properties['validationBefore']);
            $this->checkSubChecker($prop, $this->properties['validationBefore']);
        }

        foreach ($this->properties['fromJSON'] as $prop => $option) {
            $this->data[$prop] = json_decode($this->data[$prop], $option);
        }

        foreach ($this->properties['validationAfter'] as $prop => $options) {
            $this->checkType($prop, $this->properties['validationBefore']);
            $this->checkRequired($prop, $this->properties['validationBefore']);
            $this->checkSubChecker($prop, $this->properties['validationBefore']);
        }

        return count($this->errors) === 0;
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

    public function check($data){
        $this->data = $data;
        switch ($this->type) {
            case self::TYPE_ITERABLE:
                return $this->checkArray();
            case self::TYPE_OBJECT:
                return $this->checkObject();
        }
    }

    public function clearErrors(){
        $this->errors = array();
    }
}

$checker = new RequestChecker(RequestChecker::TYPE_ITERABLE);
$checkerPrueba = new RequestChecker();

$checkerPrueba->setProperty('hello')->type('integer');

$checker->setProperty('prueba')->required()->fromJSON(true)->after()->checker($checkerPrueba);
$checker->setProperty('cosa')->required()->nullable()->type('string');

$test = array(array('cosa' => "\n", 'prueba' => '{"hello": "world"}'), array('cosa' => 90, 'prueba' => '{"hello": "world"}'));

echo "##ANTES\n";
var_dump($test);
echo "##VALIDACION Y CONVERSION\n";

if( !$checker->check($test) ){
    var_dump($checker->getErrors());
}
echo "##DESPUES\n";
var_dump($checker->getData());
