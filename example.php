<?php

// Некоторые части кода "спрятаны" из соображений безопасности

namespace Fort\..\Controller;

use Fort\..\Controller\AbstractEntityController;
use Fort\..\Entity\TechModel;
use Fort\..\Manager\Catalog\TechManager;
use Fort\..\Entity\Tech;
use http\Message;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Fort\..\Controller\WorkplaceCommonController as WCC;

class TechController extends Controller
{
    const ACL_FULL = '...';
    private $wcc;

    public function __construct()
    {
        $this->wcc = new WCC($this);
    }

    public function ajaxAction(Request $request)
    {
        $post = $request->request->all();
        switch ($post['type']) {
            case 'get-models-list' :
                $jsonResponse = json_encode($this->getModelsList($post));
                break;
            case 'add-model':
                $jsonResponse = json_encode($this->addModel($post));
                break;
            case 'delete-model' :
                $jsonResponse = json_encode($this->deleteModel($post));
                break;
            case 'add-table-models':
                $jsonResponse = json_encode($this->addTableModels($post));
                break;
            case 'add-textarea-models' :
                $jsonResponse = json_encode($this->addTextareaModels($post));
                break;
            case 'approve-model':
                $jsonResponse = json_encode($this->approveModel($post));
                break;
            case 'decline-model':
                $jsonResponse = json_encode($this->declineModel($post));
                break;
            case 'smart-search':
                $jsonResponse = json_encode($this->smartSearch($post));
                break;
            case 'save-changed-model':
                $jsonResponse = json_encode($this->saveChangedModel($post));
                break;
            default:
                $jsonResponse = json_encode(['status' => 'error', 'message' => 'Не верный параметр']);

        }
        $headers = array( 'Content-type' => 'application-json; charset=utf8' );
        $response = new \Symfony\Component\HttpFoundation\Response( $jsonResponse, 200, $headers );
        return $response;
    }


    public function indexAction() {
      
        $em = $this->getDoctrine()->getManager();

        $*типы* = $em->getRepository('Fort..Bundle:*Типы*')->findAll();
        $*бренды* = $em->getRepository('Fort..Bundle:*Бренды*')->findAll();
        $*модели* = $em->getRepository('Fort..Bundle:*Модели*')->findAll();
        //модели на согласование
        $new_models = $em->getRepository('Fort..Bundle:Tech')->findBy(['*модель*' => null]) ?
            $em->getRepository('Fort..Bundle:Tech')->findBy(['*модель*' => null]) : [];

        if(!empty($new_models)) {
            foreach ($new_models as $key => $model) {
                $order = $em->getRepository('Fort..Bundle:Order')->findOneBy(['tech' => $model->getId()]);
                $new_models[$key]->info = [
                    '*логист*' => $order->getLogist() ? $order->getLogist()->getFio() : '',
                    '*заказ*' => $order->getId(),
                    '*комментарий*' => $order->getBugDescription()

                ];
            }
        }
        return $this->render('For..Bundle:Catalog:Tech/index.html.twig', [
                'tech_type' => $*типы,
                'tech_mfr' => $*бренды*,
                'tech_model' => $*модели*,
                'new_models' => $new_models
        ]
        );
    }

    /**
     * @param $post
     * @return array
     */
    public function getModelsList($post) {
        $result = $this->getDoctrine()->getRepository("Fort..Bundle:Tech")
            ->findBy(['techType'=> $post['tech_type'], 'techMfr' => $post['tech_mfr']]);
       $models = [];
       foreach ($result as $model) {
           $models['need'][] = [
               'id' => !empty($model->getTechMdl()) ? $model->getTechMdl()->getId() : '',
               'name' => $model->getTechModel()
               ] ;

       }
        $all_models = $this->getDoctrine()->getRepository("Fort..Bundle:TechModel")->findAll();
       foreach ($all_models as $model) {
           $models['all'][] =
               [
                   'id' => $model->getId(),
                   'name' => $model->getName()
               ] ;
       }

        return $models;

    }

    /**
     * @param $post
     * @return array
     */
    public function addModel($post) {
        //добавить новую модель в таблицу с моделями
        $em = $this->getDoctrine()->getManager();
        $post_model = trim(htmlspecialchars(strip_tags($post['model'])));
        if(empty($model = $this->getDoctrine()->getRepository('Fort..Bundle:TechModel')
            ->findOneBy(['name' => $post['model']]))) {
            $new_model = new *Модель*();
            $new_model->setName($post_model);
            $em->persist($new_model);
            $em->flush();
            $model = $new_model;
        }
        if(empty($tech = $this->getDoctrine()->getRepository('Fort..Bundle:Tech')
            ->findOneBy(['techType' => $post['tech_type'], 'techMfr' => $post['tech_mfr'], 'techModel' => $post_model]))) {
            //добавить связь в таблицу teach
            $new_tech = new Tech();
            $new_tech->setTechType($em->getRepository('Fort..Bundle:TechType')->find($post['tech_type']));
            $new_tech->setTechMfr($em->getRepository('Fort..Bundle:TechMfr')->find($post['tech_mfr']));
            $new_tech->setTechMdl($model);
            $new_tech->setTechModel($model->getName());
            $em->persist($new_tech);
        }
        else {
            //если связь уже существует  - вернуть ошибку
            return ['status' => 'error', 'message' => 'Такая модель уже добавлена у данного типа техники и производителя'];
        }
        $em->flush();
        $this->wcc->log(null, "{$this->getUser()->getEmployee()->getFio()} добавил модель {$model->getName()} для {$new_tech->getTechType()->getName()} | {$new_tech->getTechMfr()->getName()}", "Success", __METHOD__);
        return ['status' => 'success', 'message' => 'Модель успешно добавлена', 'id' => $model->getId()];
    }

    /**
     * @param $post
     * @return array
     */
    public function deleteModel($post) {
        $em = $this->getDoctrine()->getManager();
        $tech= $this->getDoctrine()->getRepository('Fort..Bundle:Tech')
            ->findOneBy(['techType' => $post['tech_type'], 'techMfr' => $post['tech_mfr'], 'techMdl' => $post['model']]);
        if(!empty($tech)) {
            //проверим, не привязана ли эта связь к какому либо заказу
            $orders = $em->getRepository('Fort..Bundle:Order')->findBy(['tech' => $tech->getId()]);
            if(!empty($orders)) {
                $related_orders = [];
                foreach ($orders as $order) {
                    $related_orders[$order->getId()] =  !empty($order->getLogist()) ? $order->getLogist()->getFio() : '';
                }
                return ['status' => 'error',
                    'message' => 'Не возможно удалить модель, так как она привязана к заказам:',
                    'orders' => $related_orders
                    ];
            }
            $this->wcc->log(null, "{$this->getUser()->getEmployee()->getFio()} удалил модель {$tech->getTechModel()} для {$tech->getTechType()->getName()} | {$tech->getTechMfr()->getName()}", "Success", __METHOD__);
            $em->remove($tech);
            $em->flush();
            return ['status' => 'success', 'message' => 'Модель успешно удалена'];
        }
        else {
            return ['status' => 'error', 'message' => 'не нашел модель'];
        }
    }

  /*
      Код скрыт
  */

    public function saveChangedModel($post) {
        $em = $this->getDoctrine()->getManager();
        $new_model = trim(htmlspecialchars($post['value']));
        $tech = $em->getRepository('Fort..Bundle:Tech')->find(trim($post['*id*']));
        $tech->setTechModel($new_model);
        $em->persist($tech);
        $em->flush();
    }

}
