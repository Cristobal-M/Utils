<?php

class RequestChecker{
  //Un array, se entiende que cada elemento debe validarse en funcion de lo que se defina
  const TYPE_ITERABLE = 'iterable';
  const TYPE_OBJECT = 'object';

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
    $this->properties[$name] = array('validationBefore' => [], 'fromJSON' => null, 'validationAfter' => []);
    unset($this->currentPropertySetting);
    $this->currentPropertySetting = $name;
    $this->before();

    return $this;
  }

  public function fromJSON($toArray = true){
    $this->properties[$this->currentPropertySetting]['fromJSON'] = $toArray;
    return $this;
  }

  public function required(){
    $this->properties[$this->currentPropertySetting][$this->currentPropValSetting]['required'] = true;
    return $this;
  }

  public function type($type){
    $this->properties[$this->currentPropertySetting][$this->currentPropValSetting]['type'] = $type;
    return $this;
  }

  public function before(){
    $this->currentPropValSetting = 'validationBefore';
    return $this;
  }

  public function after(){
    $this->currentPropValSetting = 'validationAfter';
    return $this;
  }

  public function checker($checkObj){
    if( ! $checkObj instanceof self ) throw new Exception('Argument is not of a instance of RequestChecker');
    $this->properties[$this->currentPropertySetting][$this->currentPropValSetting]['checker'] = $checkObj;
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

  private function doValidation($prop, $validationName, $value){
    switch ($validationName) {
      case 'required':
        if( empty($this->data[$prop]) ){
          $this->addError($validationName, "The property $prop is required");
        }
        break;
      case 'type':
        if( !empty($this->data[$prop]) && gettype($this->data[$prop]) !== $value ){
          $this->addError($validationName, "The property $prop is not of type $value");
        }
        break;
      case 'checker':
        //En este caso se realiza un chequeo sobre una propiedad
        if( !empty($this->data[$prop]) && !$value->check($this->data[$prop]) ){
          $this->addError($validationName, "The property $prop does not comply with " . json_encode($value->getErrors()) );
          //Es posible que haya habido alguna transformacion
          $this->data[$prop] = $value->getData();
          $value->clearErrors();
        }
    }
  }

  private function checkObject(){
    var_dump($this->data);
    foreach ($this->properties as $prop => $propData) {
      foreach ($propData['validationBefore'] as $name => $value) {
        $this->doValidation($prop, $name, $value);
      }
      if(!empty($propData['fromJSON'])){
        $this->data[$prop] = json_decode($this->data[$prop], $propData['fromJSON']);
      }
      foreach ($propData['validationAfter'] as $name => $value) {
        $this->doValidation($prop, $name, $value);
      }
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
$checker->setProperty('cosa')->required()->type('string');

$test = array(array('cosa' => 90, 'prueba' => '{"hello": "world"}'), array('cosa' => 90, 'prueba' => '{"hello": "world"}'));

echo "##ANTES\n";
var_dump($test);
echo "##VALIDACION Y CONVERSION\n";

if( !$checker->check($test) ){
  var_dump($checker->getErrors());
}
echo "##DESPUES\n";
var_dump($checker->getData());
