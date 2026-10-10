<?php
/** Independent Gutenberg extension tools; existing dispatchers remain compatible. */
if (!defined('ABSPATH')) { exit; }
class EMCP_Tools_Block_Pack_Integration {
    private $id;
    private $adapter;
    public function __construct(string $id) {
        $this->id=$id;
        $class=EMCP_Tools_Page_Builders::block_packs()[$id]['class'];
        $this->adapter=class_exists($class)?new $class():null;
    }
    public function definitions(): array {
        $str=array('type'=>'string'); $obj=array('type'=>'object');
        $path=array('type'=>'array','minItems'=>1,'items'=>array('type'=>'integer','minimum'=>0));
        $post=array('post_id'=>array('type'=>'integer','minimum'=>1));
        $position=array('type'=>'object','properties'=>array('mode'=>array('type'=>'string','enum'=>array('append','prepend','before','after','inside')),'path'=>$path),'required'=>array('mode'),'additionalProperties'=>false);
        $write=$post+array('expected_hash'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'));
        $defs=array(
            'get-context'=>array('Discovery',false,'Read this block integration and its independent activation state.',array(),array()),
            'list-blocks'=>array('Discovery',false,'Discover installed blocks for this plugin.',array('category'=>$str,'search'=>$str),array()),
            'get-block-schema'=>array('Discovery',false,'Inspect native attributes and authoring examples.',array('name'=>$str,'names'=>array('type'=>'array','items'=>$str),'full'=>array('type'=>'boolean')),array()),
            'get-post-blocks'=>array('Pages',false,'Read the complete mixed Gutenberg tree, saved markup and current content hash.', $post,array('post_id')),
            'add-block'=>array('Blocks',true,'Insert a plugin block using its existing native adapter. Read schema and page first.', $write+array('block'=>$str,'attributes'=>$obj,'position'=>$position),array('post_id','expected_hash','block')),
            'update-block'=>array('Blocks',true,'Replace one block belonging to this plugin with one complete serialized block. Preserve native markup and nested content.', $write+array('path'=>$path,'markup'=>$str),array('post_id','expected_hash','path','markup')),
            'move-block'=>array('Blocks',true,'Move a block belonging to this plugin within a mixed Gutenberg page.', $write+array('path'=>$path,'position'=>$position),array('post_id','expected_hash','path','position')),
            'remove-block'=>array('Blocks',true,'Remove a block belonging to this plugin, including its children.', $write+array('path'=>$path),array('post_id','expected_hash','path')),
        );
        if($this->id==='generateblocks') {
            unset($defs['add-block'][3]['attributes']);
            $defs['add-block'][3]+=array('tag_name'=>$str,'content'=>$str,'styles'=>$obj,'raw_styles'=>$obj,'media_id'=>array('type'=>'integer','minimum'=>1));
        }
        if($this->id==='kadence-blocks') {
            $defs['list-patterns']=array('Patterns',false,'Discover Kadence Design Library patterns.',array('category'=>$str,'search'=>$str,'include_pro'=>array('type'=>'boolean'),'categories'=>array('type'=>'boolean')),array());
            $defs['insert-pattern']=array('Patterns',true,'Insert a native Kadence Design Library pattern.', $write+array('pattern'=>$str,'position'=>$position,'localize_images'=>array('type'=>'boolean')),array('post_id','expected_hash','pattern'));
        }
        return $defs;
    }
    public function get_ability_names(): array {
        return array_merge(array('emcp-tools/'.$this->id.'-read','emcp-tools/'.$this->id.'-write'),array_map(fn($s)=>'emcp-tools/'.$this->id.'-'.$s,array_keys($this->definitions())));
    }
    public function allowed(array $args=array()): bool {
        return $this->adapter && EMCP_Tools_Page_Builders::enabled($this->id) && current_user_can('edit_posts') && (empty($args['post_id']) || current_user_can('edit_post',(int)$args['post_id']));
    }
    public function register(): void {
        foreach($this->definitions() as $slug=>$d) {
            emcp_tools_register_ability('emcp-tools/'.$this->id.'-'.$slug,array(
                'label'=>EMCP_Tools_Page_Builders::block_packs()[$this->id]['label'].' '.ucwords(str_replace('-',' ',$slug)),
                'description'=>$d[2],'category'=>'emcp-tools',
                'input_schema'=>array('type'=>'object','properties'=>$d[3],'required'=>$d[4],'additionalProperties'=>false),
                'permission_callback'=>fn($a=array())=>$this->allowed(is_array($a)?$a:array()),
                'execute_callback'=>fn($a=array())=>$this->execute($slug,is_array($a)?$a:array()),
                'meta'=>array('show_in_rest'=>true,'annotations'=>array('readonly'=>!$d[1],'destructive'=>in_array($slug,array('update-block','remove-block'),true),'idempotent'=>!$d[1]))));
        }
        foreach(array('read','write') as $mode) {
            emcp_tools_register_ability('emcp-tools/'.$this->id.'-'.$mode,array(
                'label'=>EMCP_Tools_Page_Builders::block_packs()[$this->id]['label'].' '.ucfirst($mode).' (Compatibility)',
                'description'=>'Existing dispatcher retained for compatibility. Prefer the individual tools for new workflows.', 'category'=>'emcp-tools',
                'input_schema'=>array('type'=>'object','properties'=>array('operation'=>array('type'=>'string'),'arguments'=>array('type'=>'object'))),
                'permission_callback'=>fn($a=array())=>$this->allowed(is_array($a['arguments']??null)?$a['arguments']:array()),
                'execute_callback'=>fn($a=array())=>$this->legacy($mode,is_array($a)?$a:array()),
                'meta'=>array('show_in_rest'=>true,'annotations'=>array('readonly'=>$mode==='read','destructive'=>false,'idempotent'=>$mode==='read'))));
        }
    }
    public function legacy(string $mode,array $input) {
        $args=is_array($input['arguments']??null)?$input['arguments']:array();
        if(!$this->allowed($args)) { return new WP_Error('block_pack_forbidden','Block integration or post permission unavailable.'); }
        if($mode==='write' && !empty($input['operation']) && empty($args['post_id'])) { return new WP_Error('invalid_post','A post ID is required.'); }
        return $this->adapter->{'run_'.$mode}($input);
    }
    private function page(int $id) {
        $post=get_post($id); if(!$post) { return new WP_Error('not_found','Post not found.'); }
        return array('post_id'=>$id,'status'=>$post->post_status,'content_hash'=>hash('sha256',$post->post_content),'markup'=>$post->post_content,'blocks'=>EMCP_Tools_Block_Tree::summarize(EMCP_Tools_Block_Tree::from_markup($post->post_content)));
    }
    public function execute(string $slug,array $args) {
        if(!$this->allowed($args)) { return new WP_Error('block_pack_forbidden','Block integration or post permission unavailable.'); }
        $defs=$this->definitions(); if(!isset($defs[$slug])) { return new WP_Error('unknown_tool','Unknown block integration tool.'); }
        if($slug==='get-context') { return array('integration'=>$this->id,'site_url'=>home_url(),'editor'=>'gutenberg','theme_required'=>$this->id==='blocksy-blocks','independent_toggle'=>true,'tools'=>$this->get_ability_names()); }
        if($slug==='get-post-blocks') { return $this->page((int)$args['post_id']); }
        if(!$defs[$slug][1]) { return $this->adapter->run_read(array('operation'=>$slug,'arguments'=>$args)); }
        $id=(int)($args['post_id']??0);
        if(!$id) { return new WP_Error('invalid_post','A post ID is required.'); }
        $lock='emcp_block_pack_lock_'.$id;
        if(!add_option($lock,time(),'',false)) { return new WP_Error('page_locked','Another block integration save is in progress.'); }
        try {
            $post=get_post($id); if(!$post || in_array($post->post_status,array('trash','auto-draft'),true)) { return new WP_Error('invalid_post','Choose an editable post.'); }
            if(!hash_equals(hash('sha256',$post->post_content),(string)($args['expected_hash']??''))) { return new WP_Error('stale_content','Page changed; read get-post-blocks again.'); }
            if(!function_exists('wp_check_post_lock')) { require_once ABSPATH.'wp-admin/includes/post.php'; }
            if(wp_check_post_lock($id)) { return new WP_Error('page_locked','Another user is editing this post.'); }
            if(in_array($slug,array('add-block','insert-pattern'),true)) {
                $result=$this->adapter->run_write(array('operation'=>$slug,'arguments'=>$args));
                return is_wp_error($result)?$result:array('result'=>$result,'page'=>$this->page($id));
            }
            $tree=EMCP_Tools_Block_Tree::from_markup($post->post_content);
            $path=$args['path']; $node=EMCP_Tools_Block_Tree::at($tree,$path);
            $namespace=array('blocksy-blocks'=>'blocksy/','spectra'=>'uagb/','kadence-blocks'=>'kadence/','generateblocks'=>'generateblocks/')[$this->id];
            if(!$node || strpos((string)$node['blockName'],$namespace)!==0) { return new WP_Error('wrong_block','Choose a block belonging to this integration.'); }
            if($slug==='remove-block') { $tree=EMCP_Tools_Block_Tree::remove($tree,$path); }
            elseif($slug==='move-block') {
                $position=$args['position']; $target=$position['path']??array();
                if(!EMCP_Tools_Block_Tree::position_is_valid($tree,$position)) { return new WP_Error('invalid_position','Target does not resolve to a block.'); }
                if(in_array($position['mode'],array('inside','before','after'),true) && array_slice($target,0,count($path))===$path) { return new WP_Error('invalid_move','Cannot move a block into itself or its descendants.'); }
                $tree=EMCP_Tools_Block_Tree::move($tree,$path,$position);
            } else {
                $new=EMCP_Tools_Block_Tree::from_markup($args['markup']);
                if(count($new)!==1 || strpos((string)$new[0]['blockName'],$namespace)!==0) { return new WP_Error('invalid_markup','Provide one serialized block belonging to this plugin.'); }
                $tree=EMCP_Tools_Block_Tree::replace($tree,$path,$new);
            }
            $result=wp_update_post(array('ID'=>$id,'post_content'=>wp_slash(EMCP_Tools_Block_Tree::to_markup($tree))),true);
            return is_wp_error($result)?$result:$this->page($id);
        } finally { delete_option($lock); }
    }
    public static function admin_categories(): array {
        $out=array();
        foreach(EMCP_Tools_Page_Builders::block_packs() as $id=>$pack) {
            if(!empty($pack['native_tools'])) {
                if(class_exists($pack['class'])) { $out=array_merge($out,$pack['class']::admin_categories()); }
                continue;
            }
            $api=new self($id);
            foreach($api->definitions() as $slug=>$d) {
                $key=$id.'_'.strtolower($d[0]);
                if(!isset($out[$key])) { $out[$key]=array('platform'=>$id,'label'=>$pack['label'].': '.$d[0],'pro'=>in_array($id,array('generateblocks','blocksy-blocks'),true),'tools'=>array()); }
                $out[$key]['tools']['emcp-tools/'.$id.'-'.$slug]=array('label'=>ucwords(str_replace('-',' ',$slug)),'description'=>$d[2],'badges'=>$d[1]?array():array('read-only'),'available'=>EMCP_Tools_Page_Builders::available($id),'requires'=>array('name'=>$pack['label'],'kind'=>'plugin'));
            }
        }
        return $out;
    }
}
