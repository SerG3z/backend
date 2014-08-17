<?php

class WeatherController extends Controller
{
	/**
	 * @var string the default layout for the views. Defaults to '//layouts/column2', meaning
	 * using two-column layout. See 'protected/views/layouts/column2.php'.
	 */
	public $layout='//layouts/column2';

	/**
	 * @return массив action фильтров
	 */
	public function filters()
	{
		return array(
			'accessControl', // perform access control for CRUD operations
			'postOnly + delete', // we only allow deletion via POST request
		);
	}

	/**
	 * Определяет правила контроля доступа.
     * Этот метод используется фильтром 'AccessControl'.
	 * @return массив правил контроля доступа.
	 */
	public function accessRules()
	{
		return array(
			array('allow',
				'actions'=>array('index', 'find', 'forecast', 'login', 'error'),
				'users'=>array('*'),
			),
			array('allow',
				'actions'=>array('create','update', 'view'),
				'users'=>array('@'),
			),
			array('allow',
				'actions'=>array('admin','delete'),
				'users'=>array('admin'),
			),
			array('deny',
				'users'=>array('*'),
			),
		);
	}

	/**
	 * Отображает экземпляр модели
	 * @param integer $id ID экземпляра для отображения
	 */
	public function actionView($id)
	{
		$this->render('view',array(
			'model'=>$this->loadModel($id),
		));
	}

	/**
	 * Создает экземпляр модели
	 * Если удаление прошло успешно, браузер будет перенаправлен на страницу 'View'.
	 */
	public function actionCreate()
	{
		$model=new Weather;

		$this->performAjaxValidation($model);

		if(isset($_POST['Weather']))
		{
			$model->attributes=$_POST['Weather'];
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('create',array(
			'model'=>$model,
		));
	}

	/**
	 * Обновляет экземпляр модели
	 * Если удаление прошло успешно, браузер будет перенаправлен на страницу 'View'.
	 * @param integer $id ID экземпляра для обновления
     */
	public function actionUpdate($id)
	{
		$model=$this->loadModel($id);

		$this->performAjaxValidation($model);

		if(isset($_POST['Weather']))
		{
			$model->attributes=$_POST['Weather'];
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('update',array(
			'model'=>$model,
		));
	}

	/**
	 * Удаляет экземпляр модели
	 * Если удаление прошло успешно, браузер будет перенаправлен на страницу 'Admin'.
	 * @param integer $id ID экземпляра для удаления
	 */
	public function actionDelete($id)
	{
		$this->loadModel($id)->delete();

		// if AJAX request (triggered by deletion via admin grid view), we should not redirect the browser
		if(!isset($_GET['ajax']))
			$this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('admin'));
	}

	/**
	 * Краткий мануал по API
	 */
	public function actionIndex()
	{
        if(Yii::app()->cache->flush()){
            echo 'Кэш успешно очищен!!';
        }
        echo "<center><h2>Пример запросов на один день</h2>" .
            "<p>Поиск по городу: <pre>/?r=weather/find&city=Novosibirsk&pr=ya</pre></p>" .
            "<p>Поиск по координатам: <pre>/?r=weather/find&lat=55.753676&lon=37.619899&pr=owm</pre></p>" .
            "<p>Поиск в пределах прямоугольника: <pre>/?r=weather/find&lon_top=82.560544&lat_top=55.174534&lon_bottom=83.318972&lat_bottom=54.843024</pre></p></center>";

        echo "<center><h2>Пример запросов на несколько дней вперед с временем суток</h2>" .
            "<p>Поиск по городу: <pre>/?r=weather/forecast&city=Novosibirsk&pr=ya</pre></p>" .
            "<p>Поиск по координатам: <pre>/?r=weather/forecast&lat=55.753676&lon=37.619899&pr=owm</pre></p>";
    }

    /**
     * Прогноз на текущий день, параметры передаются GET запросом
     * @param $city город, для которого будет осуществлятся прогноз
     * @param $lat широта города
     * @param $lon долгота города
     * @param $lat_top широта верхней левой точки
     * @param $lon_top долгота верхней левой точки
     * @param $lat_bottom широта нижней правой точки
     * @param $lon_bottom долгота нижней правой точки
     * @param $provider провайдер
     */
    public function actionFind()
    {
		$pr = ["ya" => 1, "owm" => 2]; //Провайдер

	    $out_from_db = "name_ru as city, date_forecast as date, AVG(temp) as temp, AVG(humidity) as humidity,
	                    AVG(pressure) as pressure, latitude, longitude, p.name as weather, pr.name as provider";

        $join = "weatherstation ws
                    LEFT JOIN city c ON c.id = ws.city_id
                    LEFT JOIN weather w ON w.station_id = ws.id
                    LEFT JOIN provider pr ON w.provider_id = pr.id
                    LEFT JOIN wind_deg wd ON w.wind_deg = wd.id
                    LEFT JOIN precipitation p ON w.precipitation_id = p.id";

        $city = Yii::app()->request->getQuery('city');
        $lat = Yii::app()->request->getQuery('lat');
        $lon = Yii::app()->request->getQuery('lon');
        $lon_top = Yii::app()->request->getQuery('lon_top');
        $lat_top = Yii::app()->request->getQuery('lat_top');
        $lon_bottom = Yii::app()->request->getQuery('lon_bottom');
        $lat_bottom = Yii::app()->request->getQuery('lat_bottom');
		$provider = Yii::app()->request->getQuery('pr', 'ya');
		$provider = strtr($provider, $pr);
		$today = date("Y-m-d");

        if(isset($city)) {
            $city = mb_convert_case($city, MB_CASE_TITLE, "UTF-8");
            $weather_cache = Yii::app()->cache->get("find".$city);
            if($weather_cache == false){

                $sql = "SELECT $out_from_db FROM $join
                    WHERE  (c.name_en LIKE :city OR c.name_ru LIKE :city)
                    AND date_forecast = :today AND w.provider_id = :provider";

                $weather_cache = Yii::app()->db->createCommand($sql)
                    ->bindParam(':city', $city, PDO::PARAM_STR)
                    ->bindParam(':provider', $provider, PDO::PARAM_STR)
                    ->bindParam(':today', $today, PDO::PARAM_STR)
                    ->queryAll();

                Yii::app()->cache->set("find".$city, $weather_cache, 86400);
            }
        }else if(isset($lat) && isset($lon)){
            $weather_cache = Yii::app()->cache->get("find".$lat.$lon);
            if($weather_cache == false){
                $sql = "SELECT $out_from_db FROM $join
					WHERE ws.latitude = :lat AND ws.longitude = :lon
					AND date_forecast = :today AND provider_id = :provider";

                $weather_cache = Yii::app()->db->createCommand($sql)
                    ->bindParam(':lat', $lat, PDO::PARAM_STR)
                    ->bindParam(':lon', $lon, PDO::PARAM_STR)
                    ->bindParam(':provider', $provider, PDO::PARAM_STR)
                    ->bindParam(':today', $today, PDO::PARAM_STR)
                    ->queryAll();
                Yii::app()->cache->set("find".$lat.$lon, $weather_cache, 86400);
            }
        }else if(isset($lon_top) && isset($lat_top) && isset($lon_bottom) && isset($lat_bottom)){
            $weather_cache = Yii::app()->cache->get("find".$lon_top.$lat_top.$lon_bottom.$lat_bottom);
            if($weather_cache == false){

                $sql = "SELECT $out_from_db FROM $join
                    WHERE ws.id IN (SELECT id FROM weatherstation
                                    WHERE Contains(GeomFromText('Polygon(($lat_top $lon_top, $lat_top $lon_bottom, $lat_bottom $lon_bottom,$lat_top $lon_bottom, $lat_top $lon_top))'), point))
                    AND date_forecast = :today AND partofday = 2 AND w.provider_id = :provider";

                $weather_cache = Yii::app()->db->createCommand($sql)
                    ->bindParam(':provider', $provider, PDO::PARAM_STR)
                    ->bindParam(':today', $today, PDO::PARAM_STR)
                    ->queryAll();
                Yii::app()->cache->set("find".$lon_top.$lat_top.$lon_bottom.$lat_bottom, $weather_cache, 86400);
            }
        } else{
            $ip = $_SERVER["REMOTE_ADDR"];
            $city = ParserIP::parse($ip);
            $city = mb_convert_case($city, MB_CASE_TITLE, "UTF-8");
            $weather_cache = Yii::app()->cache->get("find".$ip);
            if($weather_cache == false){

                $sql = "SELECT $out_from_db FROM $join
                    WHERE  (c.name_en LIKE :city OR c.name_ru LIKE :city)
                    AND date_forecast = :today AND w.provider_id = :provider";

                $weather_cache = Yii::app()->db->createCommand($sql)
                    ->bindParam(':city', $city, PDO::PARAM_STR)
                    ->bindParam(':provider', $provider, PDO::PARAM_STR)
                    ->bindParam(':today', $today, PDO::PARAM_STR)
                    ->queryAll();

                Yii::app()->cache->set("find".$ip, $weather_cache, 86400);
            }
        }

        header('Content-Type: application/json');
        $json = JSON::encode($weather_cache);
        printf("callback(%s)", $json);
    }

    /**
     * Прогноз на несколько дней вперед, параметры передаются GET запросом
     * @param $city город, для которого будет осуществлятся прогноз
     * @param $lat широта города
     * @param $lon долгота города
     * @param $provider провайдер
     */
    public function actionForecast(){
		$pr = ["ya" => 1, "owm" => 2,]; //Провайдер
        $dayofpart = [4 => 'night', 1 => 'morning', 2 => 'day', 3 => 'evening'];
		$city = Yii::app()->request->getQuery('city');
        $lat = Yii::app()->request->getQuery('lat');
        $lon = Yii::app()->request->getQuery('lon');
		$provider = Yii::app()->request->getQuery('pr', "ya");
		$provider = strtr($provider, $pr);
		$today = date("Y-m-d");

        $out_from_db = "name_ru as city, date_forecast as date, partofday, temp, humidity,
	                    pressure, latitude, longitude, p.name as weather, pr.name as provider";

        $join = "weatherstation ws
                    LEFT JOIN city c ON c.id = ws.city_id
                    LEFT JOIN weather w ON w.station_id = ws.id
                    LEFT JOIN provider pr ON w.provider_id = pr.id
                    LEFT JOIN wind_deg wd ON w.wind_deg = wd.id
                    LEFT JOIN precipitation p ON w.precipitation_id = p.id";
		
		if(isset($city)) {
            $city = mb_convert_case($city, MB_CASE_TITLE, "UTF-8");
            $weather_cache = Yii::app()->cache->get("forecast".$city);

            if($weather_cache == false){
                $sql = "SELECT $out_from_db
					FROM $join
					WHERE (c.name_en LIKE :city OR c.name_ru LIKE :city)
					        AND date_forecast >= :today AND provider_id = :provider
					ORDER BY date_forecast, partofday";

                $weather_cache = Yii::app()->db->createCommand($sql)
                    ->bindParam(':city', $city, PDO::PARAM_STR)
                    ->bindParam(':provider', $provider, PDO::PARAM_STR)
                    ->bindParam(':today', $today, PDO::PARAM_STR)
                    ->queryAll();

                Yii::app()->cache->set("forecast".$city, $weather_cache, 86400);
            }
        }

        if(isset($lat) && isset($lon)){
            $weather_cache = Yii::app()->cache->get("forecast".$lat.$lon);
            if($weather_cache == false){
                $sql = "SELECT $out_from_db FROM $join
					WHERE ws.latitude = :lat AND ws.longitude = :lon
					AND date_forecast >= :today AND provider_id = :provider
					ORDER BY date_forecast";

                $weather_cache = Yii::app()->db->createCommand($sql)
                    ->bindParam(':lat', $lat, PDO::PARAM_STR)
                    ->bindParam(':lon', $lon, PDO::PARAM_STR)
                    ->bindParam(':provider', $provider, PDO::PARAM_STR)
                    ->bindParam(':today', $today, PDO::PARAM_STR)
                    ->queryAll();
                Yii::app()->cache->set("forecast".$lat.$lon, $weather_cache, 86400);
            }

        }
        header('Content-Type: application/json');
		$json = JSON::encode($weather_cache);
        printf("callback(%s)", $json);		
	}

	/**
	 * Управление всеми моделями
	 */
	public function actionAdmin()
	{
		$model=new Weather('search');
		$model->unsetAttributes();
		if(isset($_GET['Weather']))
			$model->attributes=$_GET['Weather'];

		$this->render('admin',array(
			'model'=>$model,
		));
	}

	/**
	 * Возвращает модель данных на основе первичного ключа, указанному в переменной GET.
	 * Если модель данных не найдена, будет вызвано исключение.
	 * @param integer $id ID модели, которая будет загружена
	 * @return Weather загруженная модель
	 * @throws CHttpException
	 */
	public function loadModel($id)
	{
		$model=Weather::model()->findByPk($id);
		if($model===null)
			throw new CHttpException(404,'Запись не найдена');
		return $model;
	}

	/**
	 * Выполняет проверку AJAX.
	 * @param Weather $model модель, прошедшая проверку
	 */
	protected function performAjaxValidation($model)
	{
		if(isset($_POST['ajax']) && $_POST['ajax']==='weather-form')
		{
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}
	}

    public function actionError(){
        if($error=Yii::app()->errorHandler->error)
            $this->render('error', $error);
    }

    public function actionLogin(){
        $model=new LoginForm;
        if(isset($_POST['LoginForm']))
        {
            // получаем данные от пользователя
            $model->attributes=$_POST['LoginForm'];
            // проверяем полученные данные и, если результат проверки положительный,
            // перенаправляем пользователя на предыдущую страницу
            if($model->validate())
                $this->redirect(Yii::app()->user->returnUrl);
        }
        // рендерим представление
        $this->render('login',array('model'=>$model));
    }
}
