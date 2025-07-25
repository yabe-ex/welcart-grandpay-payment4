<?php

/**
 * GrandPay決済処理クラス - デバッグ強化版（金額不整合対応）
 * Welcartとの統合、チェックアウトセッション作成、コールバック処理、ポイント処理を実装
 */
class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // Welcartの決済フックに登録
        add_action('usces_action_acting_processing', array($this, 'process_payment'), 10, 2);

        // コールバック処理をより早いタイミングで登録
        add_action('wp', array($this, 'handle_payment_callback'), 1);
        add_action('template_redirect', array($this, 'handle_payment_callback'), 1);

        // Webhook処理
        add_action('wp_ajax_grandpay_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_grandpay_webhook', array($this, 'handle_webhook'));

        // REST API登録
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        error_log('GrandPay Payment Processor: Initialized with early callback hooks at ' . date('Y-m-d H:i:s'));
    }

    /**
     * Webhook用REST APIエンドポイント登録
     */
    public function register_webhook_endpoint() {
        register_rest_route('grandpay/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook_rest'),
            'permission_callback' => '__return_true'
        ));

        error_log('GrandPay Payment: REST API webhook endpoint registered at ' . date('Y-m-d H:i:s'));
    }

    /**
     * ポイントのバリデーションと一時確保
     */
    private function validate_and_process_points($used_points, $total_price, $member_id) {
        global $usces;

        error_log('GrandPay Payment: validate_and_process_points - Input: Used Points: ' . $used_points . ', Total Price: ' . $total_price . ', Member ID: ' . $member_id);
        error_log('GrandPay Payment: validate_and_process_points - Session used_points: ' . print_r($_SESSION['usces_entry']['order']['used_points'] ?? 'N/A', true));

        if ($used_points <= 0 || !$member_id) {
            error_log('GrandPay Payment: No points used or no member ID');
            return array('success' => true, 'used_points' => 0, 'points_discount' => 0, 'final_amount' => $total_price);
        }

        // 会員のポイント残高を取得
        $current_points = usces_get_member_point($member_id);
        error_log('GrandPay Payment: validate_and_process_points - Current Points: ' . $current_points);
        if ($current_points < $used_points) {
            error_log('GrandPay Payment: Insufficient points - Available: ' . $current_points . ', Requested: ' . $used_points);
            return array(
                'success' => false,
                'error' => 'ポイントが不足しています。'
            );
        }

        // ポイントレートを取得
        $point_rate = isset($usces->options['system']['point_rate']) ? $usces->options['system']['point_rate'] : 1;
        error_log('GrandPay Payment: validate_and_process_points - Point Rate: ' . $point_rate);
        $points_discount = $used_points * $point_rate;
        $final_amount = $total_price - $points_discount;
        error_log('GrandPay Payment: validate_and_process_points - Calculated: Discount: ' . $points_discount . ', Final Amount: ' . $final_amount);

        // 最小決済金額チェック
        $min_amount = isset($usces->options['grandpay']['min_amount']) ? $usces->options['grandpay']['min_amount'] : 1400;
        error_log('GrandPay Payment: validate_and_process_points - Min Amount: ' . $min_amount);
        if ($final_amount < $min_amount) {
            error_log('GrandPay Payment: Final amount below minimum (' . $min_amount . '), rejecting point usage');
            return array(
                'success' => false,
                'error' => sprintf('ポイント使用後の決済金額が最小金額（%d円）を下回るため、ポイントをご利用いただけません。', $min_amount)
            );
        }

        // ポイントを一時的に確保
        $_SESSION['usces_entry']['order']['used_points'] = $used_points;
        error_log('GrandPay Payment: validate_and_process_points - Saved to Session: used_points = ' . $used_points);

        return array(
            'success' => true,
            'used_points' => $used_points,
            'points_discount' => $points_discount,
            'final_amount' => $final_amount
        );
    }

    /**
     * 使用ポイントをセッションから取得
     */
    private function get_used_points_from_session() {
        $used_points = isset($_SESSION['usces_entry']['order']['used_points']) ?
            intval($_SESSION['usces_entry']['order']['used_points']) : 0;
        error_log('GrandPay Payment: get_used_points_from_session - Retrieved: ' . $used_points);
        error_log('GrandPay Payment: get_used_points_from_session - Full Session usces_entry: ' . print_r($_SESSION['usces_entry'] ?? [], true));
        return $used_points;
    }

    /**
     * ポイント残高を更新
     */
    private function update_member_points($member_id, $used_points) {
        global $usces;

        if ($member_id && $used_points > 0) {
            $current_points = usces_get_member_point($member_id);
            $new_points = max(0, $current_points - $used_points);
            usces_update_member_point($member_id, $new_points);
            error_log('GrandPay Payment: update_member_points - Updated: Member ID: ' . $member_id . ', Used: ' . $used_points . ', New Balance: ' . $new_points);
            return true;
        }
        error_log('GrandPay Payment: update_member_points - No update: Member ID: ' . $member_id . ', Used Points: ' . $used_points);
        return false;
    }

    /**
     * ポイント使用のロールバック
     */
    private function rollback_points_for_order($order_id) {
        $used_points = get_post_meta($order_id, '_used_points', true);
        $member_id = get_post_meta($order_id, '_member_id', true);

        if ($used_points && $member_id) {
            $current_points = usces_get_member_point($member_id);
            $new_points = $current_points + $used_points;
            usces_update_member_point($member_id, $new_points);
            error_log('GrandPay Payment: rollback_points_for_order - Rolled back: Member ID: ' . $member_id . ', Points: ' . $used_points . ', New Balance: ' . $new_points);
        } else {
            error_log('GrandPay Payment: rollback_points_for_order - No rollback: Order ID: ' . $order_id);
        }
    }

    /**
     * メイン決済処理 - Welcart決済フロー統合
     */
    public function process_payment($post_data, $acting_opts) {
        global $usces;

        error_log('GrandPay Payment: process_payment - Start at ' . date('Y-m-d H:i:s'));
        error_log('GrandPay Payment: process_payment - Full POST Data: ' . print_r($_POST, true));
        error_log('GrandPay Payment: process_payment - Post Data Argument: ' . print_r($post_data, true));
        error_log('GrandPay Payment: process_payment - Acting Opts: ' . print_r($acting_opts, true));
        error_log('GrandPay Payment: process_payment - Initial Session usces_entry: ' . print_r($_SESSION['usces_entry'] ?? [], true));

        // ポイントデータをセッションに保存
        $used_points = 0;
        if (isset($_POST['offer']['usedpoint'])) {
            $used_points = intval($_POST['offer']['usedpoint']);
            $_SESSION['usces_entry']['order']['used_points'] = $used_points;
            error_log('GrandPay Payment: process_payment - Saved used_points from offer[usedpoint]: ' . $used_points);
        } elseif (isset($post_data['offer']['usedpoint'])) {
            $used_points = intval($post_data['offer']['usedpoint']);
            $_SESSION['usces_entry']['order']['used_points'] = $used_points;
            error_log('GrandPay Payment: process_payment - Saved used_points from post_data[offer][usedpoint]: ' . $used_points);
        } else {
            $_SESSION['usces_entry']['order']['used_points'] = 0;
            error_log('GrandPay Payment: process_payment - No usedpoint found in POST or post_data');
        }
        error_log('GrandPay Payment: process_payment - Updated Session usces_entry: ' . print_r($_SESSION['usces_entry'] ?? [], true));

        // Welcartの決済設定を確認
        $acting_settings = $usces->options['acting_settings'] ?? array();
        $acting_flag = $acting_settings['acting_flag'] ?? '';
        $payment_method = $_POST['offer']['payment_method'] ?? $post_data['offer']['payment_method'] ?? '';

        // GrandPayが選択されているかチェック
        $is_grandpay_selected = false;
        if (
            $acting_flag === 'grandpay' || in_array($payment_method, array('acting_grandpay_card', 'grandpay')) ||
            (isset($_POST['offer']['payment_name']) && strpos($_POST['offer']['payment_name'], 'GrandPay') !== false)
        ) {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: process_payment - GrandPay payment detected');
        }

        if (!$is_grandpay_selected) {
            error_log('GrandPay Payment: process_payment - Not GrandPay payment, skipping');
            return;
        }

        // GrandPay設定確認
        $grandpay_options = $acting_settings['grandpay'] ?? array();
        if (($grandpay_options['activate'] ?? 'off') !== 'on') {
            error_log('GrandPay Payment: process_payment - GrandPay not activated');
            $usces->error_message = 'GrandPay決済が有効になっていません。';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        // 注文データを取得・準備
        $order_data = $this->prepare_order_data();
        if (!$order_data) {
            error_log('GrandPay Payment: process_payment - Failed to prepare order data');
            $usces->error_message = '注文データの準備に失敗しました';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        error_log('GrandPay Payment: process_payment - Order data prepared: ' . print_r($order_data, true));

        // チェックアウトセッション作成
        $result = $this->api->create_checkout_session($order_data);
        error_log('GrandPay Payment: process_payment - API Response for create_checkout_session: ' . print_r($result, true));

        if (!$result['success']) {
            error_log('GrandPay Payment: process_payment - Checkout session creation failed: ' . $result['error']);
            $usces->error_message = $result['error'] ?: '決済セッションの作成に失敗しました';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        if (isset($result['session_id']) && isset($result['checkout_url'])) {
            // 注文情報を保存
            $this->save_order_data($order_data['order_id'], $result, $order_data);
            error_log('GrandPay Payment: process_payment - Redirecting to checkout URL: ' . $result['checkout_url']);
            wp_redirect($result['checkout_url']);
            exit;
        }

        error_log('GrandPay Payment: process_payment - Unexpected error in payment processing');
        $usces->error_message = '決済処理中にエラーが発生しました';
        $this->redirect_to_cart_with_error($usces->error_message);
    }

    /**
     * 注文データを準備
     */
    private function prepare_order_data() {
        global $usces;

        try {
            // 基本データ取得
            $cart = $usces->cart;
            $member = $usces->get_member();
            // prepare_order_data メソッド内、基本データ取得部分を修正
            $total_price = isset($_SESSION['usces_entry']['order']['total_price']) ?
                $_SESSION['usces_entry']['order']['total_price'] : $usces->get_total_price();
            error_log('GrandPay Payment: prepare_order_data - Initial Total Price: ' . $total_price . ' (from session or usces)');
            error_log('GrandPay Payment: prepare_order_data - Full Cart Data: ' . print_r($cart ?? [], true));

            // 注文IDの取得
            $order_id = null;
            $is_temp_id = false;

            error_log('GrandPay Payment: prepare_order_data - ========== ORDER ID DETECTION START ==========');

            if (isset($_SESSION['usces_entry']['order_id'])) {
                $order_id = $_SESSION['usces_entry']['order_id'];
                error_log('GrandPay Payment: prepare_order_data - Order ID from session: ' . $order_id);
            } elseif (isset($_POST['order_id'])) {
                $order_id = intval($_POST['order_id']);
                error_log('GrandPay Payment: prepare_order_data - Order ID from POST: ' . $order_id);
            } elseif (isset($usces->current_order_id)) {
                $order_id = $usces->current_order_id;
                error_log('GrandPay Payment: prepare_order_data - Order ID from usces object: ' . $order_id);
            } else {
                $recent_orders = get_posts(array(
                    'post_type' => 'shop_order',
                    'post_status' => array('draft', 'private', 'publish'),
                    'numberposts' => 5,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'meta_query' => array(
                        array(
                            'key' => '_order_status',
                            'value' => array('pending', 'processing', 'new'),
                            'compare' => 'IN'
                        )
                    )
                ));

                if (!empty($recent_orders)) {
                    $order_id = $recent_orders[0]->ID;
                    error_log('GrandPay Payment: prepare_order_data - Using latest order ID: ' . $order_id);
                }
            }

            if (!$order_id) {
                $temp_id = 'TEMP_' . time() . '_' . rand(1000, 9999);
                $order_id = $temp_id;
                $is_temp_id = true;
                error_log('GrandPay Payment: prepare_order_data - Generated temporary order ID: ' . $temp_id);
                $_SESSION['usces_entry']['grandpay_temp_id'] = $temp_id;
            }

            error_log('GrandPay Payment: prepare_order_data - Final selected order ID: ' . $order_id . ' (Is temp: ' . ($is_temp_id ? 'YES' : 'NO') . ')');

            // 顧客情報の取得
            $customer_data = array();
            if (isset($_SESSION['usces_entry']['customer'])) {
                $customer_data = $_SESSION['usces_entry']['customer'];
                error_log('GrandPay Payment: prepare_order_data - Customer data from session entry');
            } elseif (isset($_POST['customer'])) {
                $customer_data = $_POST['customer'];
                error_log('GrandPay Payment: prepare_order_data - Customer data from POST');
            } elseif (isset($_SESSION['usces_member'])) {
                $customer_data = array(
                    'name1' => $_SESSION['usces_member']['mem_name1'] ?? '',
                    'name2' => $_SESSION['usces_member']['mem_name2'] ?? '',
                    'mailaddress1' => $_SESSION['usces_member']['mem_email'] ?? '',
                    'tel' => $_SESSION['usces_member']['mem_tel'] ?? ''
                );
                error_log('GrandPay Payment: prepare_order_data - Customer data from session member');
            } elseif (!empty($member)) {
                $customer_data = array(
                    'name1' => $member['mem_name1'] ?? '',
                    'name2' => $member['mem_name2'] ?? '',
                    'mailaddress1' => $member['mem_email'] ?? '',
                    'tel' => $member['mem_tel'] ?? ''
                );
                error_log('GrandPay Payment: prepare_order_data - Customer data from member');
            }

            $customer_name = trim(($customer_data['name1'] ?? '') . ' ' . ($customer_data['name2'] ?? ''));
            $customer_email = $customer_data['mailaddress1'] ?? $customer_data['email'] ?? '';
            $customer_phone = $customer_data['tel'] ?? $customer_data['phone'] ?? '';

            if (empty($customer_email)) {
                $customer_email = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
                error_log('GrandPay Payment: prepare_order_data - Using default email: ' . $customer_email);
            }

            if (empty($customer_name)) {
                $customer_name = 'お客様';
                error_log('GrandPay Payment: prepare_order_data - Using default name');
            }

            if (empty($total_price) || $total_price <= 0) {
                $total_price = 1000;
                error_log('GrandPay Payment: prepare_order_data - Using default amount: ' . $total_price);
            }

            // ポイント処理
            $member_id = $member['ID'] ?? 0;
            $used_points = $this->get_used_points_from_session();
            error_log('GrandPay Payment: prepare_order_data - Used Points before validation: ' . $used_points);
            $point_result = $this->validate_and_process_points($used_points, $total_price, $member_id);

            if (!$point_result['success']) {
                error_log('GrandPay Payment: prepare_order_data - Point validation failed: ' . $point_result['error']);
                $usces->error_message = $point_result['error'];
                return false;
            }

            $final_amount = $point_result['final_amount'];
            $points_discount = $point_result['points_discount'];
            $used_points = $point_result['used_points'];
            error_log('GrandPay Payment: prepare_order_data - After Point Validation: Final Amount: ' . $final_amount . ', Points Discount: ' . $points_discount . ', Used Points: ' . $used_points);

            // URL構築
            $base_url = home_url();
            $complete_url = $base_url . '/usces-member/?page=completionmember';
            $cart_url = $base_url . '/usces-cart/';

            if (isset($usces->url['complete_page'])) {
                $complete_url = $usces->url['complete_page'];
            }
            if (isset($usces->url['cart_page'])) {
                $cart_url = $usces->url['cart_page'];
            }

            $callback_nonce = wp_create_nonce('grandpay_callback_' . $order_id);
            $success_url = add_query_arg(array(
                'grandpay_result' => 'success',
                'order_id' => $order_id,
                'session_check' => $callback_nonce
            ), $complete_url);

            $failure_url = add_query_arg(array(
                'grandpay_result' => 'failure',
                'order_id' => $order_id,
                'session_check' => $callback_nonce
            ), $cart_url);

            error_log('GrandPay Payment: prepare_order_data - Generated callback URLs:');
            error_log('GrandPay Payment: prepare_order_data - Success URL: ' . $success_url);
            error_log('GrandPay Payment: prepare_order_data - Failure URL: ' . $failure_url);

            $order_data = array(
                'order_id' => $order_id,
                'amount' => intval($final_amount),
                'original_amount' => intval($total_price),
                'used_points' => $used_points,
                'points_discount' => $points_discount,
                'name' => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
                'success_url' => $success_url,
                'failure_url' => $failure_url,
                'is_temp_id' => $is_temp_id,
                'member_id' => $member_id
            );

            error_log('GrandPay Payment: prepare_order_data - Final order data: ' . print_r($order_data, true));
            return $order_data;
        } catch (Exception $e) {
            error_log('GrandPay Payment: prepare_order_data - Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 注文データを保存
     */
    private function save_order_data($order_id, $payment_result, $order_data) {
        error_log('GrandPay Payment: save_order_data - ========== SAVE ORDER DATA START ==========');
        error_log('GrandPay Payment: save_order_data - Order ID: ' . $order_id);
        error_log('GrandPay Payment: save_order_data - Order Data: ' . print_r($order_data, true));

        if (isset($order_data['is_temp_id']) && $order_data['is_temp_id']) {
            error_log('GrandPay Payment: save_order_data - Handling temporary order ID: ' . $order_id);
            $_SESSION['grandpay_temp_order'] = array(
                'temp_id' => $order_id,
                'session_id' => $payment_result['session_id'],
                'checkout_url' => $payment_result['checkout_url'],
                'created_at' => current_time('mysql'),
                'order_data' => $order_data
            );

            $actual_order_id = $this->find_or_create_actual_order($order_data, $payment_result);

            if ($actual_order_id) {
                error_log('GrandPay Payment: save_order_data - Found/Created actual order ID: ' . $actual_order_id);
                update_post_meta($actual_order_id, '_grandpay_temp_order_id', $order_id);
                update_post_meta($actual_order_id, '_grandpay_session_id', $payment_result['session_id']);
                update_post_meta($actual_order_id, '_grandpay_checkout_url', $payment_result['checkout_url']);
                update_post_meta($actual_order_id, '_payment_method', 'grandpay');
                update_post_meta($actual_order_id, '_grandpay_payment_status', 'pending');
                update_post_meta($actual_order_id, '_grandpay_created_at', current_time('mysql'));
                update_post_meta($actual_order_id, '_customer_email', $order_data['email']);
                update_post_meta($actual_order_id, '_customer_name', $order_data['name']);
                update_post_meta($actual_order_id, '_customer_phone', $order_data['phone']);
                update_post_meta($actual_order_id, '_order_total', $order_data['amount']);
                update_post_meta($actual_order_id, '_original_amount', $order_data['original_amount']);
                update_post_meta($actual_order_id, '_used_points', $order_data['used_points']);
                update_post_meta($actual_order_id, '_points_discount', $order_data['points_discount']);
                update_post_meta($actual_order_id, '_member_id', $order_data['member_id']);

                $_SESSION['grandpay_temp_order']['actual_order_id'] = $actual_order_id;
            } else {
                error_log('GrandPay Payment: save_order_data - Failed to find or create actual order for temp ID: ' . $order_id);
            }
        } else {
            error_log('GrandPay Payment: save_order_data - Handling normal order ID: ' . $order_id);
            $order = get_post($order_id);
            if (!$order) {
                error_log('GrandPay Payment: save_order_data - Order not found for ID: ' . $order_id);
                return false;
            }

            update_post_meta($order_id, '_grandpay_session_id', $payment_result['session_id']);
            update_post_meta($order_id, '_grandpay_checkout_url', $payment_result['checkout_url']);
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_grandpay_payment_status', 'pending');
            update_post_meta($order_id, '_grandpay_created_at', current_time('mysql'));
            update_post_meta($order_id, '_customer_email', $order_data['email']);
            update_post_meta($order_id, '_customer_name', $order_data['name']);
            update_post_meta($order_id, '_customer_phone', $order_data['phone']);
            update_post_meta($order_id, '_order_total', $order_data['amount']);
            update_post_meta($order_id, '_original_amount', $order_data['original_amount']);
            update_post_meta($order_id, '_used_points', $order_data['used_points']);
            update_post_meta($order_id, '_points_discount', $order_data['points_discount']);
            update_post_meta($order_id, '_member_id', $order_data['member_id']);
        }

        error_log('GrandPay Payment: save_order_data - ========== SAVE ORDER DATA END ==========');
        return true;
    }

    /**
     * 実際の注文を検索または作成
     */
    private function find_or_create_actual_order($order_data, $payment_result) {
        global $usces;

        error_log('GrandPay Payment: find_or_create_actual_order - ========== FIND OR CREATE ORDER START ==========');

        $search_criteria = array(
            array(
                'post_type' => 'shop_order',
                'post_status' => array('draft', 'private', 'publish'),
                'numberposts' => 10,
                'orderby' => 'date',
                'order' => 'DESC',
                'date_query' => array(
                    array(
                        'after' => '30 minutes ago'
                    )
                )
            ),
            array(
                'post_type' => 'shop_order',
                'post_status' => array('draft', 'private', 'publish'),
                'numberposts' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_customer_email',
                        'value' => $order_data['email'],
                        'compare' => '='
                    )
                )
            )
        );

        foreach ($search_criteria as $index => $criteria) {
            $orders = get_posts($criteria);
            if (!empty($orders)) {
                $selected_order = $this->select_best_matching_order($orders, $order_data);
                if ($selected_order) {
                    error_log('GrandPay Payment: find_or_create_actual_order - Selected order ID: ' . $selected_order->ID);
                    return $selected_order->ID;
                }
            }
        }

        error_log('GrandPay Payment: find_or_create_actual_order - No matching order found, creating new order');
        $created_order_id = $this->create_order_from_session($order_data, $payment_result);

        if ($created_order_id) {
            error_log('GrandPay Payment: find_or_create_actual_order - Successfully created new order: ' . $created_order_id);
            return $created_order_id;
        }

        error_log('GrandPay Payment: find_or_create_actual_order - Failed to find or create order');
        return false;
    }

    /**
     * 最適な注文を選択
     */
    private function select_best_matching_order($orders, $order_data) {
        foreach ($orders as $order) {
            $existing_session = get_post_meta($order->ID, '_grandpay_session_id', true);
            if (!empty($existing_session)) {
                continue;
            }

            $order_total = get_post_meta($order->ID, '_order_total', true);
            if (empty($order_total)) {
                $order_total = get_post_meta($order->ID, '_total_full_price', true);
            }

            if (abs(intval($order_total) - intval($order_data['amount'])) <= 10) {
                return $order;
            }
        }

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * セッション情報から注文を作成
     */
    private function create_order_from_session($order_data, $payment_result) {
        global $usces;

        error_log('GrandPay Payment: create_order_from_session - Creating new order from session data');

        try {
            $new_order_id = usces_new_order_id();
            if (!$new_order_id) {
                $order_post = array(
                    'post_type' => 'shop_order',
                    'post_status' => 'private',
                    'post_title' => 'Order #' . time(),
                    'post_content' => 'GrandPay Order',
                    'post_author' => get_current_user_id()
                );

                $new_order_id = wp_insert_post($order_post);
                if (is_wp_error($new_order_id)) {
                    error_log('GrandPay Payment: create_order_from_session - Failed to create order post: ' . $new_order_id->get_error_message());
                    return false;
                }
            }

            update_post_meta($new_order_id, '_order_date', current_time('mysql'));
            update_post_meta($new_order_id, '_order_status', 'pending');
            update_post_meta($new_order_id, '_order_total', $order_data['amount']);
            update_post_meta($new_order_id, '_original_amount', $order_data['original_amount']);
            update_post_meta($new_order_id, '_used_points', $order_data['used_points']);
            update_post_meta($new_order_id, '_points_discount', $order_data['points_discount']);
            update_post_meta($new_order_id, '_customer_email', $order_data['email']);
            update_post_meta($new_order_id, '_customer_name', $order_data['name']);
            update_post_meta($new_order_id, '_customer_phone', $order_data['phone']);
            update_post_meta($new_order_id, '_payment_method', 'grandpay');
            update_post_meta($new_order_id, '_member_id', $order_data['member_id']);

            if (isset($usces->cart) && !empty($usces->cart->cart)) {
                update_post_meta($new_order_id, '_cart', $usces->cart->cart);
            }

            if (isset($_SESSION['usces_entry']['customer'])) {
                update_post_meta($new_order_id, '_customer_data', $_SESSION['usces_entry']['customer']);
            }

            return $new_order_id;
        } catch (Exception $e) {
            error_log('GrandPay Payment: create_order_from_session - Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 決済完了後のコールバック処理
     */
    public function handle_payment_callback() {
        ob_start();
        static $callback_processed = false;
        if ($callback_processed) {
            error_log('GrandPay Payment: handle_payment_callback - Callback already processed, skipping at ' . date('Y-m-d H:i:s'));
            return;
        }

        error_log('GrandPay Payment: handle_payment_callback - ========== CALLBACK DEBUG START ==========');
        error_log('GrandPay Payment: handle_payment_callback - Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log('GrandPay Payment: handle_payment_callback - GET parameters: ' . print_r($_GET, true));

        if (!isset($_GET['grandpay_result']) || !isset($_GET['order_id'])) {
            error_log('GrandPay Payment: handle_payment_callback - Missing required parameters');
            return;
        }

        $callback_processed = true;
        $order_id = sanitize_text_field($_GET['order_id']);
        $result = sanitize_text_field($_GET['grandpay_result']);
        $session_check = $_GET['session_check'] ?? '';

        if (empty($session_check) || !wp_verify_nonce($session_check, 'grandpay_callback_' . $order_id)) {
            error_log('GrandPay Payment: handle_payment_callback - Invalid callback nonce for order: ' . $order_id);
            wp_die('Invalid session.', 'Callback Error', array('response' => 403));
            return;
        }

        $order = null;
        $final_order_id = $order_id;

        if (is_numeric($order_id)) {
            $order = get_post(intval($order_id));
        }

        if (!$order && strpos($order_id, 'TEMP_') === 0) {
            $orders = get_posts(array(
                'post_type' => 'shop_order',
                'meta_key' => '_grandpay_temp_order_id',
                'meta_value' => $order_id,
                'post_status' => 'any',
                'numberposts' => 1
            ));

            if (!empty($orders)) {
                $order = $orders[0];
                $final_order_id = $order->ID;
            } elseif (isset($_SESSION['grandpay_temp_order']['actual_order_id'])) {
                $final_order_id = $_SESSION['grandpay_temp_order']['actual_order_id'];
                $order = get_post($final_order_id);
            } elseif (isset($_SESSION['grandpay_temp_order']['order_data'])) {
                $session_order_data = $_SESSION['grandpay_temp_order']['order_data'];
                $session_payment_result = array(
                    'session_id' => $_SESSION['grandpay_temp_order']['session_id'],
                    'checkout_url' => $_SESSION['grandpay_temp_order']['checkout_url']
                );
                $created_order_id = $this->create_order_from_session($session_order_data, $session_payment_result);
                if ($created_order_id) {
                    $order = get_post($created_order_id);
                    $final_order_id = $created_order_id;
                    update_post_meta($final_order_id, '_grandpay_temp_order_id', $order_id);
                }
            }
        }

        if (!$order) {
            error_log('GrandPay Payment: handle_payment_callback - Order not found: ' . $order_id);
            wp_die('Order not found.', 'Callback Error', array('response' => 404));
            return;
        }

        $order_id = $final_order_id;
        $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);

        if (in_array($current_status, array('completed', 'failed'))) {
            error_log('GrandPay Payment: handle_payment_callback - Order already processed with status: ' . $current_status);
            if ($current_status === 'completed') {
                $this->redirect_to_complete_page($order_id);
            } else {
                $this->redirect_to_cart_with_error('この注文は既に処理済みです');
            }
            return;
        }

        if ($result === 'success') {
            $this->handle_success_callback($order_id);
        } elseif ($result === 'failure') {
            $this->handle_failure_callback($order_id);
        } else {
            error_log('GrandPay Payment: handle_payment_callback - Unknown callback result: ' . $result);
            wp_die('Invalid callback result.', 'Callback Error', array('response' => 400));
        }

        error_log('GrandPay Payment: handle_payment_callback - ========== CALLBACK DEBUG END ==========');
    }

    /**
     * 完了ページへのリダイレクト処理
     */
    private function redirect_to_complete_page($order_id) {
        global $usces;

        $complete_url = $usces->url['complete_page'] ?? home_url('/usces-member/?page=completionmember');
        $redirect_url = add_query_arg('order_id', $order_id, $complete_url);

        error_log('GrandPay Payment: redirect_to_complete_page - Redirecting to complete page: ' . $redirect_url);
        ob_end_clean();
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * 成功時のコールバック処理
     */
    private function handle_success_callback($order_id) {
        error_log('GrandPay Payment: handle_success_callback - Processing success callback for order: ' . $order_id . ' at ' . date('Y-m-d H:i:s'));

        update_post_meta($order_id, '_grandpay_payment_status', 'processing');
        update_post_meta($order_id, '_grandpay_callback_received_at', current_time('mysql'));

        $session_id = get_post_meta($order_id, '_grandpay_session_id', true);

        if ($session_id) {
            $status_result = $this->api->get_payment_status($session_id);

            error_log('GrandPay Payment: handle_success_callback - API Response for get_payment_status: ' . print_r($status_result, true));

            if ($status_result['success'] && isset($status_result['data']['data'])) {
                $payment_data = $status_result['data']['data'];
                $actual_payment_status = '';
                $payment_transaction_id = '';

                if (isset($payment_data['payments']) && !empty($payment_data['payments'])) {
                    $latest_payment = end($payment_data['payments']);
                    $actual_payment_status = $latest_payment['status'] ?? '';
                    $payment_transaction_id = $latest_payment['id'] ?? '';
                }

                $final_status = strtoupper($actual_payment_status ?: $payment_data['status'] ?? '');
                $success_statuses = array('COMPLETED', 'COMPLETE', 'SUCCESS', 'SUCCEEDED', 'PAID', 'AUTHORIZED', 'CONFIRMED');

                if (in_array($final_status, $success_statuses)) {
                    $this->complete_order($order_id, $payment_data);
                    $this->redirect_to_complete_page($order_id);
                } else {
                    error_log('GrandPay Payment: handle_success_callback - Payment not completed, status: ' . $final_status);
                    update_post_meta($order_id, '_grandpay_payment_status', 'pending');
                    update_post_meta($order_id, '_grandpay_pending_reason', $final_status);
                    $this->redirect_to_complete_page($order_id);
                }
            } else {
                error_log('GrandPay Payment: handle_success_callback - Failed to get payment status: ' . print_r($status_result, true));
                update_post_meta($order_id, '_grandpay_payment_status', 'pending');
                update_post_meta($order_id, '_grandpay_status_check_failed', current_time('mysql'));
                $this->redirect_to_complete_page($order_id);
            }
        } else {
            error_log('GrandPay Payment: handle_success_callback - Session ID not found for order: ' . $order_id);
            update_post_meta($order_id, '_grandpay_payment_status', 'error');
            $this->redirect_to_cart_with_error('セッション情報が見つかりません。');
        }
    }

    /**
     * 失敗時のコールバック処理
     */
    private function handle_failure_callback($order_id) {
        error_log('GrandPay Payment: handle_failure_callback - Processing failure callback for order: ' . $order_id . ' at ' . date('Y-m-d H:i:s'));
        $this->fail_order($order_id);
        $this->redirect_to_cart_with_error('決済に失敗しました。');
    }

    /**
     * 注文完了処理
     */
    private function complete_order($order_id, $payment_data) { // $order_id は wp_posts のIDです
        global $usces, $wpdb;

        $welcart_order_db_id = usces_reg_orderdata(
            array(
                'acting' => 'grandpay', // 決済代行コード
                'order_date' => current_time('mysql'), // 注文日時
                'payment_name' => $order_data['payment_name'] ?? 'GrandPay', // 決済方法名
                // その他の acting_results に関連する情報があれば追加
            )
        );

        $this->process_inventory_update($welcart_order_db_id);
        $this->send_completion_notifications($welcart_order_db_id);

        error_log('GrandPay Payment: Starting complete_order for order_id: ' . $order_id);
        error_log('GrandPay Payment: Payment data: ' . print_r($payment_data, true));

        try {
            // 🔧 重複処理防止
            $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
            if ($current_status === 'completed') {
                error_log('GrandPay Payment: Order already completed: ' . $order_id);
                return;
            }

            // 1. Welcart標準の注文ステータス更新
            update_post_meta($order_id, '_order_status', 'ordercompletion'); // _order_statusメタを更新

            // wp_posts.post_status を 'publish' に更新（Welcartの成功時のデフォルト）
            $order_post = array(
                'ID' => $order_id,
                'post_status' => 'publish' // Welcartの成功時またはデフォルトの公開状態
            );
            $update_post_result = wp_update_post($order_post);

            if (is_wp_error($update_post_result) || $update_post_result === 0) {
                error_log('GrandPay Payment: FAILED to update wp_posts.post_status for order ' . $order_id . ': ' . (is_wp_error($update_post_result) ? $update_post_result->get_error_message() : 'Unknown error'));
            } else {
                error_log('GrandPay Payment: SUCCESSFULLY updated wp_posts.post_status to "' . $order_post['post_status'] . '" for order ' . $order_id);
            }

            // ★★★ ここから修正/最終版 ★★★
            // _grandpay_welcart_db_order_id メタから wp_usces_order のIDを取得
            // $welcart_db_order_id = get_post_meta($order_id, '_grandpay_welcart_db_order_id', true);

            $transient_key = 'grandpay_welcart_db_order_id_';
            $welcart_db_order_id = get_transient($transient_key);

            if (empty($welcart_db_order_id)) {
                // _grandpay_welcart_db_order_id が見つからない場合は、正しい wp_usces_order.ID を特定できないため、更新をスキップします。
                error_log('GrandPay Payment: CRITICAL ERROR - _grandpay_welcart_db_order_id meta not found for wp_posts ID: ' . $order_id . '. Cannot update wp_usces_order table.');
                return;
            }

            // 在庫更新
            $this->process_inventory_update($welcart_db_order_id);
            $this->send_completion_notifications($welcart_db_order_id);

            $target_db_order_id = $welcart_db_order_id;
            error_log('GrandPay Payment: Retrieved wp_usces_order DB ID from meta: ' . $target_db_order_id . ' for wp_posts ID: ' . $order_id);

            // wp_usces_order テーブルの order_status フィールドを更新
            $order_table_name = $wpdb->prefix . 'usces_order';
            $update_result_db_order = $wpdb->update(
                $order_table_name,
                array('order_status' => 'ordercompletion'), // 成功時のWelcart内部ステータス
                array('ID' => $target_db_order_id) // 正しく取得した wp_usces_order テーブルのIDを使用
            );

            if (false === $update_result_db_order) {
                error_log('GrandPay Payment: FAILED to update wp_usces_order status for DB ID ' . $target_db_order_id . ': ' . $wpdb->last_error);
            } else {
                error_log('GrandPay Payment: SUCCESSFULLY updated wp_usces_order status to "ordercompletion" for DB ID ' . $target_db_order_id);
            }
            // ★★★ ここまで修正/最終版 ★★★

            // 2. 決済情報を更新
            update_post_meta($order_id, '_grandpay_payment_status', 'completed');
            update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
            update_post_meta($order_id, '_acting_return', 'success');
            update_post_meta($order_id, '_grandpay_payment_id', $payment_data['id']); // 決済IDを保存

            error_log('GrandPay Payment: Order completed - ID: ' . $order_id . ', Payment ID: ' . $payment_data['id']);

            // 3. 成功フックを実行
            do_action('grandpay_payment_completed', $order_id, $payment_data);
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in complete_order: ' . $e->getMessage());
        }
    }



    /**
     * 在庫管理処理
     */
    private function process_inventory_update($order_id) {
        try {
            if (function_exists('usces_update_item_stock')) {
                $cart_data = get_post_meta($order_id, '_cart', true);
                if ($cart_data && is_array($cart_data)) {
                    foreach ($cart_data as $cart_item) {
                        $post_id = $cart_item['post_id'] ?? 0;
                        $sku = $cart_item['sku'] ?? '';
                        $quantity = intval($cart_item['quantity'] ?? 0);
                        if ($post_id && $sku && $quantity > 0) {
                            $stock_result = usces_update_item_stock($post_id, $sku, $quantity);
                            error_log("GrandPay Payment: process_inventory_update - Stock updated for {$post_id}:{$sku} (-{$quantity}): " . print_r($stock_result, true));
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: process_inventory_update - Error: ' . $e->getMessage());
        }
    }

    /**
     * 完了通知メール送信
     */
    private function send_completion_notifications($order_id) {
        try {
            if (function_exists('usces_send_ordermail')) {
                $mail_result = usces_send_ordermail($order_id);
                error_log('GrandPay Payment: send_completion_notifications - Order completion mail sent: ' . print_r($mail_result, true));
            }
        } catch (Exception $e) {
            error_log('GrandPay Payment: send_completion_notifications - Error: ' . $e->getMessage());
        }
    }

    /**
     * 注文失敗処理
     */
    private function fail_order($order_id) {
        try {
            $current_status = get_post_meta($order_id, '_grandpay_payment_status', true);
            if ($current_status === 'failed') {
                error_log('GrandPay Payment: fail_order - Order already failed: ' . $order_id);
                return;
            }

            // ポイントをロールバック
            $this->rollback_points_for_order($order_id);

            update_post_meta($order_id, '_order_status', 'cancel');
            update_post_meta($order_id, '_grandpay_payment_status', 'failed');
            update_post_meta($order_id, '_grandpay_failed_at', current_time('mysql'));
            update_post_meta($order_id, '_acting_return', 'failure');

            do_action('grandpay_payment_failed', $order_id);
        } catch (Exception $e) {
            error_log('GrandPay Payment: fail_order - Exception: ' . $e->getMessage());
        }
    }

    /**
     * エラー時のリダイレクト
     */
    private function redirect_to_cart_with_error($error_message) {
        global $usces;

        $cart_url = home_url('/usces-cart/');
        $redirect_url = add_query_arg('grandpay_error', urlencode($error_message), $cart_url);

        error_log('GrandPay Payment: redirect_to_cart_with_error - Redirecting to cart with error: ' . $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * REST API Webhook処理
     */
    public function handle_webhook_rest($request) {
        error_log('GrandPay Payment: handle_webhook_rest - REST API Webhook received at ' . date('Y-m-d H:i:s'));

        $body = $request->get_body();
        $data = json_decode($body, true);

        if (!$data || (!isset($data['eventName']) && !isset($data['type']))) {
            error_log('GrandPay Payment: handle_webhook_rest - Invalid webhook payload');
            return new WP_Error('invalid_payload', 'Invalid JSON payload', array('status' => 400));
        }

        $event_type = $data['eventName'] ?? $data['type'] ?? '';

        switch ($event_type) {
            case 'payment.payment.done':
            case 'PAYMENT_CHECKOUT':
            case 'checkout.session.completed':
            case 'payment.succeeded':
                $this->process_payment_webhook($data);
                break;
            case 'payment.failed':
                $this->process_payment_failure_webhook($data);
                break;
            default:
                error_log('GrandPay Payment: handle_webhook_rest - Unknown webhook event: ' . $event_type);
                break;
        }

        return rest_ensure_response(array('status' => 'ok', 'message' => 'Webhook processed'));
    }

    /**
     * 旧形式のWebhook処理
     */
    public function handle_webhook() {
        error_log('GrandPay Payment: handle_webhook - Legacy webhook received at ' . date('Y-m-d H:i:s'));

        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_GRANDPAY_SIGNATURE'] ?? '';

        $request = new WP_REST_Request('POST', '/grandpay/v1/webhook');
        $request->set_body($payload);
        $request->set_header('x-grandpay-signature', $signature);

        $response = $this->handle_webhook_rest($request);

        if (is_wp_error($response)) {
            wp_die($response->get_error_message(), 'Webhook Error', array('response' => 400));
        }

        wp_die('OK', 'Webhook Success', array('response' => 200));
    }

    /**
     * 決済成功Webhook処理
     */
    private function process_payment_webhook($data) {
        error_log('GrandPay Payment: process_payment_webhook - ========== WEBHOOK ORDER CREATION START ==========');

        $payment_id = $data['data']['id'] ?? '';
        $session_id = $data['data']['metadata']['checkoutSessionId'] ?? '';
        $payment_status = $data['data']['status'] ?? '';
        $amount = floatval($data['data']['amount'] ?? 0);
        $currency = $data['data']['currency'] ?? 'JPY';
        $customer_email = $data['data']['to'] ?? '';
        $customer_name = $data['data']['recipientName'] ?? '';
        $product_names = $data['data']['productNames'] ?? array();

        error_log('GrandPay Payment: process_payment_webhook - Webhook Data: ' . print_r($data, true));

        if (strtoupper($payment_status) !== 'COMPLETED') {
            error_log('GrandPay Payment: process_payment_webhook - Payment not completed, status: ' . $payment_status);
            return false;
        }

        $existing_order_id = $this->find_order_by_session_id($session_id);
        if (!$existing_order_id) {
            $existing_order_id = $this->find_order_by_payment_id($payment_id);
        }

        if ($existing_order_id) {
            $current_status = get_post_meta($existing_order_id, '_grandpay_payment_status', true);
            if ($current_status === 'completed') {
                error_log('GrandPay Payment: process_payment_webhook - Order already completed, skipping');
                return true;
            }

            $this->complete_existing_order($existing_order_id, $data['data']);
            return true;
        }

        $new_order_id = $this->create_welcart_order_from_webhook($data['data']);
        if ($new_order_id) {
            $this->complete_existing_order($new_order_id, $data['data']);
            return true;
        }

        error_log('GrandPay Payment: process_payment_webhook - Failed to create Welcart order from webhook');
        return false;
    }

    /**
     * 既存注文を完了状態に更新
     */
    private function complete_existing_order($order_id, $payment_data) {
        error_log('GrandPay Payment: complete_existing_order - Completing existing order: ' . $order_id . ' at ' . date('Y-m-d H:i:s'));

        $used_points = get_post_meta($order_id, '_used_points', true);
        $member_id = get_post_meta($order_id, '_member_id', true);
        if ($used_points && $member_id) {
            $this->update_member_points($member_id, $used_points);
        }

        update_post_meta($order_id, '_grandpay_payment_status', 'completed');
        update_post_meta($order_id, '_grandpay_transaction_id', $payment_data['id'] ?? '');
        update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
        update_post_meta($order_id, '_grandpay_payment_data', $payment_data);
        update_post_meta($order_id, '_wc_trans_id', $payment_data['id'] ?? '');
        update_post_meta($order_id, '_order_status', 'ordercompletion');
        update_post_meta($order_id, '_acting_return', 'completion');

        wp_update_post(array(
            'ID' => $order_id,
            'post_status' => 'publish'
        ));

        do_action('grandpay_payment_completed', $order_id, $payment_data);
        do_action('usces_action_order_completion', $order_id);
    }

    /**
     * WebhookからWelcart注文を作成
     */
    private function create_welcart_order_from_webhook($payment_data) {
        global $usces, $wpdb;

        error_log('GrandPay Payment: create_welcart_order_from_webhook - Creating Welcart order from webhook data at ' . date('Y-m-d H:i:s'));

        try {
            $new_order_id = usces_new_order_id();
            if (!$new_order_id) {
                error_log('GrandPay Payment: create_welcart_order_from_webhook - Failed to generate new order ID');
                return false;
            }

            $order_post = array(
                'post_type' => 'shop_order',
                'post_status' => 'private',
                'post_title' => 'Order #' . $new_order_id,
                'post_content' => 'GrandPay Webhook Order',
                'post_author' => 0,
                'post_date' => current_time('mysql')
            );

            $order_id = wp_insert_post($order_post);
            if (is_wp_error($order_id)) {
                error_log('GrandPay Payment: create_welcart_order_from_webhook - Failed to create order post: ' . $order_id->get_error_message());
                return false;
            }

            $payment_id = $payment_data['id'] ?? '';
            $session_id = $payment_data['metadata']['checkoutSessionId'] ?? '';
            $amount = floatval($payment_data['amount'] ?? 0);
            $customer_email = $payment_data['to'] ?? '';
            $customer_name = $payment_data['recipientName'] ?? '';
            $product_names = $payment_data['productNames'] ?? array();

            $member_id = 0;
            if ($customer_email) {
                $member = $wpdb->get_row($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->prefix}usces_member WHERE mem_email = %s",
                    $customer_email
                ));
                if ($member) {
                    $member_id = $member->ID;
                }
            }

            update_post_meta($order_id, '_grandpay_payment_id', $payment_id);
            update_post_meta($order_id, '_grandpay_session_id', $session_id);
            update_post_meta($order_id, '_order_total', $amount);
            update_post_meta($order_id, '_original_amount', $amount);
            update_post_meta($order_id, '_used_points', 0);
            update_post_meta($order_id, '_points_discount', 0);
            update_post_meta($order_id, '_customer_email', $customer_email);
            update_post_meta($order_id, '_customer_name', $customer_name);
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_order_status', 'pending');
            update_post_meta($order_id, '_order_date', current_time('mysql'));
            update_post_meta($order_id, '_member_id', $member_id);

            $cart_data = array();
            if (!empty($product_names)) {
                foreach ($product_names as $index => $product_name) {
                    $cart_data[] = array(
                        'post_id' => 0,
                        'item_name' => $product_name,
                        'price' => $amount / max(1, count($product_names)),
                        'quantity' => 1
                    );
                }
            } else {
                $cart_data[] = array(
                    'post_id' => 0,
                    'item_name' => 'GrandPay Purchase',
                    'price' => $amount,
                    'quantity' => 1
                );
            }
            update_post_meta($order_id, '_cart', $cart_data);

            error_log('GrandPay Payment: create_welcart_order_from_webhook - Created Welcart order ' . $order_id . ' from webhook');
            return $order_id;
        } catch (Exception $e) {
            error_log('GrandPay Payment: create_welcart_order_from_webhook - Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 決済失敗Webhook処理
     */
    private function process_payment_failure_webhook($data) {
        error_log('GrandPay Payment: process_payment_failure_webhook - Processing payment failure webhook at ' . date('Y-m-d H:i:s'));

        $payment_id = $data['data']['id'] ?? '';
        $session_id = $data['data']['metadata']['checkoutSessionId'] ?? '';

        $order_id = $this->find_order_by_session_id($session_id);
        if (!$order_id) {
            $order_id = $this->find_order_by_payment_id($payment_id);
        }

        if ($order_id) {
            error_log('GrandPay Payment: process_payment_failure_webhook - Found order for failure webhook: ' . $order_id);
            $this->fail_order($order_id);
            update_post_meta($order_id, '_grandpay_failure_reason', $data['data']['error'] ?? 'Webhook failure');
        } else {
            error_log('GrandPay Payment: process_payment_failure_webhook - No order found for failure webhook');
        }
    }

    /**
     * セッションIDで注文を検索
     */
    private function find_order_by_session_id($session_id) {
        if (empty($session_id)) {
            return false;
        }

        $orders = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_session_id',
            'meta_value' => $session_id,
            'post_status' => 'any',
            'numberposts' => 1
        ));

        return !empty($orders) ? $orders[0]->ID : false;
    }

    /**
     * 決済IDで注文を検索
     */
    private function find_order_by_payment_id($payment_id) {
        if (empty($payment_id)) {
            return false;
        }

        $orders = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_transaction_id',
            'meta_value' => $payment_id,
            'post_status' => 'any',
            'numberposts' => 1
        ));

        return !empty($orders) ? $orders[0]->ID : false;
    }
}
