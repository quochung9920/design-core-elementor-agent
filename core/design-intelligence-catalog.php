<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local UI/UX knowledge catalog for the agent design brain.
 * Merges the bundled starter snapshot with maintained real-world verticals.
 */
class Design_Core_Elementor_Design_Intelligence_Catalog {
    const SCHEMA_VERSION = 2;
    const DEFAULT_RELATIVE_PATH = 'resources/design-intelligence/catalog.json';
    private $path; private $data = null;
    public function __construct( $path = '' ) { $this->path = $path ? (string)$path : DESIGN_CORE_ELEMENTOR_PATH . self::DEFAULT_RELATIVE_PATH; }
    public function load() {
        if ( null !== $this->data ) return $this->data;
        if ( ! is_readable( $this->path ) ) return new WP_Error('design_core_design_catalog_missing','The Design Intelligence catalog is not available.');
        $raw=file_get_contents($this->path); $decoded=json_decode((string)$raw,true);
        if ( !is_array($decoded) || 1 !== (int)($decoded['schema_version']??0) ) return new WP_Error('design_core_design_catalog_invalid','The Design Intelligence catalog has an unsupported source schema.');
        $starter=array_values(array_filter((array)($decoded['product_profiles']??array()),'is_array'));
        $decoded['source_schema_version']=1; $decoded['schema_version']=self::SCHEMA_VERSION; $decoded['starter_profile_count']=count($starter);
        $decoded['product_profiles']=$this->merge_profiles($starter,$this->builtin_profiles());
        $decoded['ux_rules']=array_values(array_filter((array)($decoded['ux_rules']??array()),'is_array'));
        return $this->data=$decoded;
    }
    public function status(){ $d=$this->load(); if(is_wp_error($d)) return array('available'=>false,'schema_version'=>self::SCHEMA_VERSION,'error'=>$d->get_error_code(),'profiles'=>0,'ux_rules'=>0); return array('available'=>true,'schema_version'=>self::SCHEMA_VERSION,'source_schema_version'=>(int)($d['source_schema_version']??1),'profiles'=>count($d['product_profiles']),'starter_profiles'=>(int)($d['starter_profile_count']??0),'builtin_vertical_profiles'=>max(0,count($d['product_profiles'])-(int)($d['starter_profile_count']??0)),'ux_rules'=>count($d['ux_rules']),'source'=>(array)($d['source']??array())); }
    public function profiles(){ $d=$this->load(); return is_wp_error($d)?array():$d['product_profiles']; }
    public function profile($id){$id=sanitize_key((string)$id);foreach($this->profiles() as $p)if($id===sanitize_key((string)($p['id']??'')))return $p;return null;}
    public function ux_rules($categories=array(),$limit=50){$d=$this->load();if(is_wp_error($d))return array();$wanted=array_values(array_filter(array_map('sanitize_key',(array)$categories)));$out=array();foreach($d['ux_rules'] as $r){$c=sanitize_key((string)($r['category']??''));if($wanted&&!in_array($c,$wanted,true))continue;$out[]=$r;if(count($out)>=max(1,min(200,(int)$limit)))break;}return $out;}
    public function match_profile($brief,$explicit_product_type=''){
        $profiles=$this->profiles(); if(!$profiles)return new WP_Error('design_core_design_profiles_unavailable','No Design Intelligence product profiles are available.');
        $q=trim((string)$explicit_product_type.' '.(string)$brief);$qn=$this->normalize_text($q);$qt=$this->tokens($q);$best=null;$best_score=-1;$best_evidence=array();
        foreach($profiles as $i=>$p){$id=sanitize_key((string)($p['id']??''));$cat=(string)($p['category']??'');$kw=array_values(array_filter(array_map('strval',(array)($p['keywords']??array()))));$score=0;$ev=array();
            if('b2b-service'===$id&&false!==strpos($qn,'enterprise')&&(false!==strpos($qn,'manpower')||false!==strpos($qn,'workforce'))){$score+=30;$ev[]='b2b-workforce-intent';}
            $cn=$this->normalize_text($cat);$idw=str_replace('-',' ',$id);
            if($explicit_product_type){$e=$this->normalize_text($explicit_product_type);if($e===$cn||$e===$this->normalize_text($idw)){$score+=80;$ev[]='explicit-product-exact';}elseif($e&&false!==strpos($cn.' '.$this->normalize_text(implode(' ',$kw)),$e)){$score+=28;$ev[]='explicit-product-partial';}}
            if($cn&&false!==strpos($qn,$cn)){$score+=32;$ev[]='category-phrase';} if($idw&&false!==strpos($qn,$this->normalize_text($idw))){$score+=24;$ev[]='profile-id';}
            foreach($kw as $k){$kn=$this->normalize_text($k);if($kn!==''&&false!==strpos($qn,$kn)){$score+=count(explode(' ',$kn))>1?11:6;$ev[]='keyword:'.sanitize_key(str_replace(' ','-',$kn));}}
            $pt=$this->tokens($cat.' '.implode(' ',$kw).' '.implode(' ',(array)($p['considerations']??array())));$over=array_intersect($qt,$pt);if($over){$score+=min(24,count(array_unique($over))*2);$ev[]='token-overlap:'.count(array_unique($over));}$score+=max(0,0.001-$i*0.000001);
            if($score>$best_score){$best=$p;$best_score=$score;$best_evidence=array_values(array_unique($ev));}
        }
        if(!is_array($best))return new WP_Error('design_core_design_profile_not_found','No Design Intelligence profile matched this request.');
        return array('profile'=>$best,'score'=>round($best_score,3),'confidence'=>$best_score>=36?'high':($best_score>=14?'medium':'low'),'evidence'=>$best_evidence,'query_tokens'=>array_slice($qt,0,40));
    }
    private function merge_profiles(array $a,array $b){$idx=array();$order=array();foreach(array_merge($a,$b) as $p){$id=sanitize_key((string)($p['id']??''));if(!$id)continue;if(!isset($idx[$id]))$order[]=$id;$idx[$id]=isset($idx[$id])?array_replace_recursive($idx[$id],$p):$p;}$o=array();foreach($order as $id)$o[]=$idx[$id];return $o;}
    private function builtin_profiles(){
        $specs=array(
            array('veterinary','Veterinary Clinic','veterinary vet animal hospital pet clinic pets dog cat emergency','medical','veterinary-home','hero emergency booking services veterinarians facilities testimonials care-plans resources cta'),
            array('medical-clinic','Medical Clinic','medical clinic doctor physician healthcare health appointment patient','medical','medical-home','hero booking services doctors trust facilities testimonials faq cta'),
            array('dental-clinic','Dental Clinic','dentist dental clinic dentistry teeth orthodontic appointment','medical','medical-home','hero booking services doctors before-after testimonials faq cta'),
            array('hospital','Hospital / Health System','hospital healthcare departments specialists emergency patients','medical','medical-home','hero emergency departments doctors locations patient-resources cta'),
            array('physiotherapy','Physiotherapy Clinic','physio physiotherapy physical therapy rehabilitation pain clinic','medical','medical-home','hero services specialists process testimonials booking cta'),
            array('mental-health','Mental Health Practice','psychology psychologist therapy therapist counseling mental health','medical','medical-home','hero services clinicians approach resources booking cta'),
            array('law-firm','Law Firm','law lawyer attorney legal solicitor litigation counsel','professional','service-home','hero practice-areas proof attorneys cases process cta'),
            array('accounting','Accounting Firm','accounting accountant tax bookkeeping audit finance cpa','professional','service-home','hero services industries proof team resources cta'),
            array('finance','Financial Services','finance financial advisory wealth investment planning','professional','service-home','hero services trust advisors process resources cta'),
            array('insurance','Insurance Agency','insurance policy broker quote coverage home auto business','professional','service-home','hero quote products benefits proof faq cta'),
            array('consulting','Consulting Firm','consulting consultant strategy transformation advisory','professional','service-home','hero capabilities industries case-studies process team cta'),
            array('real-estate','Real Estate Agency','real estate property properties homes realtor agent buy sell rent','property','real-estate-home','hero property-search featured-properties services agents areas testimonials cta'),
            array('property-developer','Property Developer','property developer development apartment condominium residential commercial','property','real-estate-home','hero projects locations features progress team cta'),
            array('construction','Construction Company','construction contractor builder commercial residential project','industrial','service-home','hero services projects capabilities safety process cta'),
            array('architecture','Architecture Studio','architecture architect studio projects buildings design planning','creative','portfolio-home','hero projects services approach team awards cta'),
            array('interior-design','Interior Design Studio','interior design interiors home commercial renovation studio','creative','portfolio-home','hero projects services process testimonials cta'),
            array('restaurant','Restaurant','restaurant dining food menu reservation chef dinner lunch','hospitality','restaurant-home','hero menu story signature-dishes gallery reservation location'),
            array('cafe','Cafe / Coffee Shop','cafe coffee bakery brunch menu location order','hospitality','restaurant-home','hero menu story gallery location cta'),
            array('hotel','Hotel','hotel rooms accommodation booking stay hospitality suites','hospitality','hotel-home','hero booking rooms amenities gallery location reviews cta'),
            array('resort','Resort','resort vacation spa rooms villas booking destination','hospitality','hotel-home','hero booking stays experiences dining gallery reviews cta'),
            array('travel','Travel Agency','travel tours trips holiday vacation destinations booking','hospitality','service-home','hero destinations packages why-us reviews faq cta'),
            array('school','School','school education students admissions curriculum teachers campus','education','education-home','hero programs admissions campus faculty news cta'),
            array('university','University / College','university college higher education degree programs admissions campus','education','education-home','hero programs research admissions campus news cta'),
            array('online-course','Online Course Platform','course courses learning training academy lessons certification','education','education-home','hero course-grid outcomes instructors reviews pricing faq cta'),
            array('training-provider','Professional Training','training professional development certification workshops education','education','education-home','hero courses credentials trainers reviews faq cta'),
            array('automotive','Automotive Business','automotive car cars vehicle service garage','automotive','service-home','hero services vehicles proof reviews booking cta'),
            array('car-dealer','Car Dealership','car dealer dealership vehicles inventory used cars new cars finance','automotive','ecommerce-home','hero vehicle-search inventory finance trade-in reviews cta'),
            array('auto-repair','Auto Repair Shop','auto repair mechanic garage service mot tires brakes oil','automotive','service-home','hero booking services proof reviews location cta'),
            array('gym','Gym / Fitness Club','gym fitness workout membership classes trainer strength','wellness','service-home','hero classes trainers membership facilities reviews cta'),
            array('fitness-coach','Fitness Coach','fitness coach personal trainer workout coaching online','wellness','service-home','hero programs results about reviews pricing cta'),
            array('salon','Hair / Beauty Salon','salon hair beauty stylist haircut color appointment','wellness','service-home','hero services team gallery reviews booking cta'),
            array('spa','Spa / Wellness Center','spa wellness massage facial treatment relaxation booking','wellness','service-home','hero treatments packages experience reviews booking cta'),
            array('manufacturing','Manufacturing Company','manufacturing factory industrial production engineering oem','industrial','service-home','hero capabilities products industries quality facilities cta'),
            array('logistics','Logistics Company','logistics freight transport shipping warehouse supply chain','industrial','service-home','hero services network industries tracking proof cta'),
            array('engineering','Engineering Company','engineering industrial technical mechanical electrical civil','industrial','service-home','hero capabilities projects industries quality team cta'),
            array('agency','Creative / Digital Agency','agency digital creative marketing web design branding','creative','portfolio-home','hero work services process proof team cta'),
            array('portfolio','Professional Portfolio','portfolio personal work projects case studies designer developer','creative','portfolio-home','hero projects about capabilities testimonials contact'),
            array('photography','Photography Studio','photography photographer photos wedding portrait commercial','creative','portfolio-home','hero gallery services about reviews booking'),
            array('marketplace','Marketplace','marketplace vendors listings products sellers buyers directory','commerce','ecommerce-home','hero search categories featured trust how-it-works cta'),
            array('directory','Directory / Listings','directory listings search businesses professionals locations','commerce','ecommerce-home','hero search categories featured locations cta'),
            array('membership','Membership Organization','membership members community association join benefits','community','service-home','hero benefits programs events community pricing cta'),
            array('nonprofit','Nonprofit / Charity','nonprofit charity donate donation cause volunteer impact','community','service-home','hero mission impact programs stories donate cta'),
            array('event','Event / Conference','event conference summit tickets speakers schedule venue','community','service-home','hero speakers agenda tickets venue sponsors faq cta'),
            array('news','News / Magazine','news magazine editorial articles stories journalism','content','blog-home','hero featured latest categories newsletter'),
            array('blog','Blog / Content Site','blog articles content author editorial resources','content','blog-home','hero featured article-grid categories newsletter'),
            array('software-product','Software Product','software product application platform technology app','technology','saas-home','hero demo features integrations proof pricing cta'),
            array('developer-tool','Developer Tool','developer tool api sdk code devops infrastructure','technology','saas-home','hero demo docs features benchmarks pricing cta'),
            array('cybersecurity','Cybersecurity Company','cybersecurity security threat protection compliance soc','technology','service-home','hero solutions proof platform resources cta'),
            array('recruitment','Recruitment Agency','recruitment staffing jobs talent hiring workforce candidates','professional','service-home','hero jobs services industries process proof cta'),
            array('home-services','Home Services','plumber electrician hvac roofing cleaning home service local','local','service-home','hero services areas proof reviews quote cta'),
            array('pet-services','Pet Services','pet grooming boarding daycare training dog cat','local','service-home','hero services packages gallery reviews booking cta'),
            array('local-business','Local Service Business','local business service appointment quote near me','local','service-home','hero services proof reviews location cta')
        );$out=array();foreach($specs as $s)$out[]=$this->make_profile($s);return $out;
    }
    private function make_profile($s){
        $presets=array('medical'=>array('#256C5A','#1E4B43','#D97757','#F7FAF8','#17312F','Inter','Inter'),'professional'=>array('#0F2A43','#294861','#176B87','#F7F9FC','#142635','Manrope','Inter'),'property'=>array('#173B3F','#315B5E','#B7793F','#F7F7F3','#1D292B','Manrope','Inter'),'industrial'=>array('#183153','#334E68','#C5672E','#F5F7FA','#172433','Manrope','Inter'),'creative'=>array('#191919','#555555','#B45F3C','#FAF9F7','#171717','Manrope','Inter'),'hospitality'=>array('#294C3D','#6C7D62','#A35F3F','#FBF8F2','#2E352F','DM Sans','Inter'),'education'=>array('#164E63','#256D85','#D97706','#F8FAFC','#17313A','Manrope','Inter'),'automotive'=>array('#172554','#334155','#EA580C','#F8FAFC','#111827','Manrope','Inter'),'wellness'=>array('#5D6F64','#899A8D','#A46858','#FAF8F5','#333D38','DM Sans','Inter'),'commerce'=>array('#163A2B','#315F49','#C65D31','#F8FAF8','#173029','Manrope','Inter'),'community'=>array('#234E70','#3E6F8E','#D97706','#F8FAFC','#183047','Manrope','Inter'),'content'=>array('#1F2937','#475569','#B45309','#FCFCFB','#111827','Source Sans 3','Source Sans 3'),'technology'=>array('#172554','#334155','#2563EB','#F8FAFC','#0F172A','Geist','Inter'),'local'=>array('#244E45','#4C7168','#C76B45','#FAF9F6','#253834','Manrope','Inter'));
        $p=$presets[$s[3]]??$presets['professional']; return array('id'=>$s[0],'category'=>$s[1],'keywords'=>preg_split('/\s+/',trim($s[2])),'pattern'=>'Industry-specific conversion journey','pattern_id'=>'industry-conversion','styles'=>array('Accessible & Ethical','Responsive Editorial Grid','Purpose-led Components'),'color_mood'=>'Purpose-led industry palette','typography_mood'=>'Clear hierarchy and readable body copy','heading_font'=>$p[5],'body_font'=>$p[6],'effects'=>array('subtle hover feedback','short purposeful transitions'),'anti_patterns'=>array('generic SaaS composition','purple/pink AI gradients','decorative motion without UX value','excessive cards and pills'),'recommended_shell'=>$s[4],'shell_fit'=>'strong','sections'=>preg_split('/\s+/',trim($s[5])),'colors'=>array('primary'=>$p[0],'on_primary'=>'#FFFFFF','secondary'=>$p[1],'on_secondary'=>'#FFFFFF','accent'=>$p[2],'on_accent'=>'#FFFFFF','background'=>$p[3],'foreground'=>$p[4],'card'=>'#FFFFFF','card_foreground'=>$p[4],'muted'=>'#EEF2F1','muted_foreground'=>'#5F6F6B','border'=>'#DCE5E2','destructive'=>'#B42318','on_destructive'=>'#FFFFFF','ring'=>$p[0]),'defaults'=>array('variance'=>5,'motion'=>3,'density'=>5),'considerations'=>array('primary user task','trust evidence','clear information architecture','strong mobile journey','realistic imagery','accessible conversion path'),'business_goals'=>array('clarify offering','build trust','move the visitor to the next meaningful action'),'image_style'=>'Authentic people, places, product or service evidence with consistent art direction.','content_hierarchy'=>array('primary outcome','proof','offering','decision support','conversion'),'mobile_priority'=>array('primary CTA visible early','short scan paths','no horizontal overflow','44px minimum interactive targets'));
    }
    private function normalize_text($v){$v=strtolower(wp_strip_all_tags((string)$v));$v=preg_replace('/[^a-z0-9]+/i',' ',$v);return trim(preg_replace('/\s+/',' ',$v));}
    private function tokens($v){$t=preg_split('/\s+/',$this->normalize_text($v));$stop=array('the','and','for','with','from','this','that','website','page','site','design','make','build','create','a','an','of','to','in','on','or','is','it');$o=array();foreach((array)$t as $x)if(strlen($x)>=2&&!in_array($x,$stop,true))$o[]=$x;return array_values(array_unique($o));}
}
