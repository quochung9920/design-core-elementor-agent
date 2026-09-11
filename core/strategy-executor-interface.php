<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Design_Core_Elementor_Strategy_Executor_Interface {
    public function supports( array $plan_item, array $context );
    public function preflight( array $plan_item, array $context );
    public function execute( array $plan_item, array $context );
    public function validate( array $result, array $context );
    public function rollback( array $result, array $context );
}
