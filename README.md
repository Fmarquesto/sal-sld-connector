# sal-sld-connector
SAPB1 ServiceLayer Connector with PHP

## Usage

```php
use App\SAPBusinessOneConnector;

// create de connector
$sapConnector = new SAPBusinessOneConnector('user','pass','db','host','port');

// make a login request
if($sapConnector->login()){
  echo "success login";
}else{
  echo "error while login in: {$sapConnector->getLoginErrorMsg()}";
}
```
