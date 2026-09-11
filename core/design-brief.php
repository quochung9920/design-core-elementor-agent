<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Converts an open-ended agent request into a stable, reviewable design brief. */
class Design_Core_Elementor_Design_Brief {
    const SCHEMA_VERSION = 1;

    public function parse( $brief, array $options = array() ) {
        $brief = trim( sanitize_textarea_field( (string) $brief ) );
        if ( '' === $brief ) { return new WP_Error( 'design_core_design_brief_required', 'A non-empty design brief is required.' ); }
        $text = $this->lower( $brief );
        $goals = $this->matches( $text, array(
            'appointment_booking'=>array('appointment','booking','book a','schedule'),
            'lead_generation'=>array('lead','quote','enquiry','inquiry','contact','consultation'),
            'commerce'=>array('shop','store','buy','cart','checkout','product'),
            'reservation'=>array('reservation','reserve','table','room booking'),
            'membership'=>array('membership','join','member','subscription'),
            'donation'=>array('donate','donation','charity'),
            'content_consumption'=>array('blog','magazine','news','article','resource'),
            'portfolio'=>array('portfolio','case study','projects','work showcase'),
        ) );
        if ( ! $goals ) { $goals[] = 'inform_and_convert'; }

        $audiences = $this->matches( $text, array(
            'pet_owners'=>array('pet','dog','cat','veterinary','vet'),
            'patients'=>array('patient','medical','clinic','doctor','dental'),
            'business_buyers'=>array('b2b','enterprise','business','company','procurement'),
            'consumers'=>array('customer','consumer','shop','retail'),
            'students'=>array('student','course','school','university','education'),
            'travelers'=>array('hotel','resort','travel','tour','guest'),
            'property_seekers'=>array('real estate','property','home buyer','renter'),
            'job_seekers'=>array('job','candidate','recruitment','career'),
        ) );

        $requested_pages = $this->matches( $text, array(
            'home'=>array('homepage','home page','home'),
            'services'=>array('services','service page'),
            'pricing'=>array('pricing','price'),
            'about'=>array('about'),
            'contact'=>array('contact'),
            'team'=>array('team','doctors','veterinarians','staff','attorneys','trainers'),
            'booking'=>array('appointment','booking','reservation'),
            'blog'=>array('blog','news','resources','articles'),
            'shop'=>array('shop','store','products'),
            'account'=>array('account','portal','dashboard','login'),
            'locations'=>array('locations','branches','clinic location'),
            'emergency'=>array('emergency','urgent'),
        ) );

        $reference_mode = 'none';
        if ( false !== strpos( $text, 'figma' ) ) { $reference_mode = 'figma'; }
        elseif ( false !== strpos( $text, 'html' ) ) { $reference_mode = 'html'; }
        elseif ( false !== strpos( $text, 'screenshot' ) || false !== strpos( $text, 'image reference' ) || false !== strpos( $text, 'reference image' ) ) { $reference_mode = 'screenshot'; }
        elseif ( ! empty( $options['reference_target'] ) ) { $reference_mode = 'rendered-target'; }

        return array(
            'schema_version'=>self::SCHEMA_VERSION,
            'type'=>'design-brief',
            'brief'=>$brief,
            'product_type'=>sanitize_text_field( (string) ( $options['product_type'] ?? '' ) ),
            'primary_goal'=>$goals[0],
            'goals'=>$goals,
            'audiences'=>$audiences,
            'requested_pages'=>$requested_pages,
            'reference_mode'=>$reference_mode,
            'reference_target'=>isset($options['reference_target']) ? esc_url_raw((string)$options['reference_target']) : '',
            'constraints'=>array(
                'language'=>sanitize_key( (string) ( $options['language'] ?? 'en' ) ),
                'mode'=>in_array(($options['mode']??'light'),array('light','dark'),true)?($options['mode']??'light'):'light',
                'mobile_first'=>true,
                'accessibility'=>'wcag-aa',
                'native_elementor_preferred'=>true,
            ),
            'quality_contract'=>array(
                'architecture'=>'required',
                'responsive'=>'required',
                'interaction'=>'required_for_interactive_ui',
                'visual'=>'required_when_reference_exists',
            ),
        );
    }

    private function matches( $text, array $rules ) {
        $out = array();
        foreach ( $rules as $key=>$needles ) {
            foreach ( $needles as $needle ) { if ( false !== strpos( $text, $needle ) ) { $out[]=$key; break; } }
        }
        return array_values( array_unique( $out ) );
    }
    private function lower( $value ) { return function_exists('mb_strtolower') ? mb_strtolower((string)$value) : strtolower((string)$value); }
}
