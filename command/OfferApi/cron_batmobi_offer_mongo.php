<?php
/**
 * 每30分钟拉一次batmobi api 任务计划
 * @changelog 修复可能接口请求失败，导致全部下架的bug
 *            优化Offer唯一标识逻辑，添加Aff ID
 *            简化函数逻辑
 * @version 1.3.8 build 20180329
 */

/**
 * 业务逻辑
 * 
 * 每次刷新api, 需要判断已入库的，如果api没有再返回，需要自动把正式数据表里的停掉；
 * 如果有返回，需要同步更新信息及状态，保持信息一致。
 * 如果还没有入库，则仅同步更新筛选数据表的信息。
 */

// 12,42  *  *  *  * root /usr/bin/php /data/www/command_jc/OfferApi/cron_batmobi_offer.php 2>&1 > /dev/null &
error_reporting(0);
set_time_limit(0);
date_default_timezone_set('PRC');

define('DEBUG_MODE', true);
define('ENABLED_AUTO_UPDATE', true);


require_once dirname(__FILE__).'/../Core/Batmobi.class.php';
require_once dirname(__FILE__).'/../Common/common.php';

if(DEBUG_MODE){
    $config = include dirname(__FILE__)."/../Conf/debug.php";
}else{
    $config = include dirname(__FILE__)."/../Conf/config.php";
}



$redis = new Redis(); 
$connected = $redis->connect($config['REDIS_HOST'], $config['REDIS_PORT']);
if($connected) $redis->auth($config['REDIS_PWD']);

$mongo = new MongoClient($config['MONGODB_SERVER']);
$db = $mongo->$config['MONGODB_NAME'];


$batmobi = new Batmobi($config['BATMOBI_APP_KEY']);
$aff_id     = $config['BATMOBI_AFF_ID'];
$network_id = 77; // network traffic

echo sprintf("------------------------------------ \n");
$timestamp = time();
$current_date = date('c', $timestamp);
echo sprintf("当前时间: %s \n", $current_date);




// 传入page参数，以此反复，直至返回{"flag":"fail","msg":"no available offer"}为止。
// {"flag":"success","msg":"success.","totalPage":11,"totalApp":2017}
$api_request_status = false;
$offer_list = array();


function get_offer_list_via_page($page, $limit, $batmobi, &$offer_list, &$api_request_status){
    $params = array(
        'page'  => $page,
        'limit' => $limit,
    );
    $json = $batmobi->get_offer($params);

    if($json['status'] == 200){
        echo sprintf("1、调用Batmobi Offer API接口成功 \n");
        if(!$json['offers']){
           return; 
        }

        $api_request_status = true;

        $total_pages = $json['pages'];
        echo sprintf("page: %s, limit: %s, total_pages: %s \n", $page, $limit, $total_pages);
        
        foreach ($json['offers'] as $key => $offer) {

            $offer_list[] = $offer;

        }
        if($total_pages > $page){
            unset($json);
            $page++;
            get_offer_list_via_page($page, $limit, $batmobi, $offer_list, $api_request_status);
        }
    }else{
        echo sprintf("[error] 调用Batmobi Offer API接口失败。 msg: %s \n", $json['msg']);
        $api_request_status = false;
    }
}

$page  = 1;
$limit = 100; // TODO 正式环境修改为大一点，总170多
get_offer_list_via_page($page, $limit, $batmobi, $offer_list, $api_request_status);


// 定义api接口返回的offer id列表，用于暂停未返回的
$api_offerid_list = array();
if($offer_list){
    echo sprintf("{ offers_count: %d } \n", count($offer_list));
    echo sprintf("2、循环判断返回的offer是否已存在临时库中，如果有，则匹配更新字段；如果没有，则添加 \n");
    foreach ($offer_list as $key => $rs) {

        $thirdparty_offer_id = $rs['camp_id'].'';

        $api_offerid_list[] = $thirdparty_offer_id;

        // 判断是否在临时库，如果有，则匹配更新字段; 如果没有，则添加。
        $offer_api = get_offer_api($thirdparty_offer_id, $network_id, $aff_id, $db, $redis);
        if(!$offer_api){
            echo sprintf("%s, %d, %s 还不存在临时库中，开始添加到临时库 \n", $thirdparty_offer_id, $network_id, $aff_id);

            // !!!
            $conversion_map = array(
                'CPI' => array('CPI', 'CPI'),
                'CPS' => array('CPS', 'CPS'),
                'CPA' => array('CPA', ''),
            );
            if($rs['acquisition_flow']){
                $payout_type     = $conversion_map[$rs['acquisition_flow']][0];
                $conversion_flow = $conversion_map[$rs['acquisition_flow']][1];
            }

            $connector_type = '3G,4G,WiFi';
            // $creative_list = array();
            // if($rs['creative_files']){
            //     foreach ($rs['creative_files'] as $creative_file) {
            //         $creative_list[] = $creative_file['link'];
            //     }
            // }

            $data = array(
                'thirdparty_offer_id' => $rs['camp_id'].'',
                'name'                => $rs['name'],
                'network_id'          => $network_id,
                'aff_id'              => $aff_id,
                'app_name'            => $rs['name'],
                'app_desc'            => '',
                'url'                 => $rs['click_url'],
                'icon_link'           => '',
                'country_code'        => join(',', array_flip(array_flip($rs['countries']))),
                'platform'            => strtolower($rs['mobile_platform']),
                'description'         => '',
                'kpi_info'            => '',
                'category'            => '',
                'min_version'         => $rs['mobile_min_version'],
                'package_name'        => $rs['mobile_app_id'],
                'preview_link'        => $rs['preview_url'],
                'payout_type'         => $payout_type ? $payout_type : '',
                'conversion_flow'     => $conversion_flow ? $conversion_flow : '',
                'carriers'            => '',
                'connector_type'      => $connector_type,

                'price'               => floatval($rs['payout_amount']),
                'creatives'           => '',
                'cvn_cap'             => $rs['daily_cap'] !=0 ? $rs['daily_cap'] : -1,
                'deviceid_must'       => -1,

                'is_imported'         => 0,
                'is_actived'          => 1,
                'update_time'         => time(),
                'create_time'         => time(),
            );
            
            $response = $db->offer_apis->insert($data);
            if($response['ok']){
                echo sprintf("添加OfferApi成功 \n");
            }else{
                echo sprintf("[error] 添加OfferApi失败 \n");
            }

        }else{
            echo sprintf("%s, %d, %s 已存在临时库中，开始更新匹配临时库 \n", $thirdparty_offer_id, $network_id, $aff_id);
            
            // 若存在offer_api库中，只判断api接口中的主要部分数据是否发生变化，再来更新offer_api
            $new_price   = floatval($rs['payout_amount']);
            $new_cvn_cap = $rs['daily_cap'] !=0 ? $rs['daily_cap'] : -1;

            update_offer_api($new_price, $new_cvn_cap, $offer_api, $thirdparty_offer_id, $network_id, $aff_id, $db, $redis);


            if(ENABLED_AUTO_UPDATE){
                echo sprintf("  %d,%d,%s 判断是否已存在正式库中，如果有，则匹配更新字段。 \n", $thirdparty_offer_id, $network_id, $aff_id);
                update_offer($new_price, $new_cvn_cap, $thirdparty_offer_id, $network_id, $aff_id, $db);
            }// if(ENABLED_AUTO_UPDATE){
        }
    }

}

// 接口请求成功，才进行处理，防止误伤（接口请求失败，结果把正式库Offer全部都停掉了）
if($api_request_status){

    // 需要判断已入库的，如果api没有再返回，需要自动把临时数据表里的暂停
    echo sprintf("3、如果api没有再返回，需要自动把OfferApi数据表里的停掉 \n");
    pause_offer_api($api_offerid_list, $network_id, $aff_id, $db, $redis);


    if(ENABLED_AUTO_UPDATE){
        echo sprintf("4、判断已入库的，如果api没有再返回，需要自动把正式数据表里的停掉 \n");
        pause_offer($api_offerid_list, $network_id, $aff_id, $db);
    } // if(ENABLED_AUTO_UPDATE){

} // if($api_request_status){

$redis->close();

?>