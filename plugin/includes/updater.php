<?php
/** GitHub Releases only. Never installs a branch/source archive as a plugin. */
final class Decka_Updater {
    const REPO='kaulpl/decka-ticket';
    const CACHE='decka_github_release_v1';
    public static function init():void {
        add_filter('update_plugins_github.com',[self::class,'update'],10,4);
        add_filter('plugins_api',[self::class,'information'],10,3);
        add_filter('upgrader_pre_download',[self::class,'download'],10,4);
        add_action('upgrader_process_complete',function($upgrader,$extra){if(($extra['type']??'')==='plugin')delete_site_transient(self::CACHE);},10,2);
    }
    public static function file():string{return plugin_basename(DECKA_FILE);}
    private static function json(string $url,int $limit=1048576):array {
        $r=wp_safe_remote_get($url,['timeout'=>15,'redirection'=>3,'limit_response_size'=>$limit,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'Decka-Bilety/'.DECKA_VERSION,'X-GitHub-Api-Version'=>'2022-11-28']]);
        if(is_wp_error($r))throw new RuntimeException('Nie można połączyć się z GitHub. Spróbuj ponownie.');
        $status=wp_remote_retrieve_response_code($r);
        if($status===404)throw new RuntimeException('Nie znaleziono publicznego wydania lub pliku w repozytorium.',404);
        if($status===403||$status===429)throw new RuntimeException('GitHub ograniczył liczbę zapytań. Spróbuj później.',$status);
        if($status!==200)throw new RuntimeException('GitHub zwrócił błąd HTTP '.$status.'.');
        $data=json_decode(wp_remote_retrieve_body($r),true);if(!is_array($data))throw new RuntimeException('Niepoprawna odpowiedź GitHub.');return $data;
    }
    public static function validate(array $release,array $manifest):array {
        $tag=(string)($release['tag_name']??'');$version=ltrim($tag,'v');
        if(!preg_match('/^v?\d+\.\d+\.\d+$/',$tag)||!empty($release['draft'])||!empty($release['prerelease']))throw new RuntimeException('Wydanie nie jest stabilną wersją.');
        if(($manifest['version']??'')!==$version||($manifest['slug']??'')!=='decka-bilety')throw new RuntimeException('Manifest nie pasuje do wydania.');
        if(!preg_match('/^[a-f0-9]{64}$/',(string)($manifest['sha256']??'')))throw new RuntimeException('Brak poprawnej sumy SHA-256 paczki.');
        $name='decka-bilety-'.$version.'.zip';$asset=null;foreach($release['assets']??[] as $a)if(($a['name']??'')===$name)$asset=$a;
        $expected='https://github.com/'.self::REPO.'/releases/download/'.$tag.'/'.$name;
        if(!$asset||($asset['browser_download_url']??'')!==$expected||($manifest['asset']??'')!==$name)throw new RuntimeException('Brak zgodnej paczki instalacyjnej ZIP.');
        foreach(['requires','requires_php'] as $key)if(!preg_match('/^\d+\.\d+(\.\d+)?$/',(string)($manifest[$key]??'')))throw new RuntimeException('Brak wymagań środowiska w manifeście.');
        return ['version'=>$version,'package'=>$expected,'sha256'=>$manifest['sha256'],'requires'=>$manifest['requires'],'requires_php'=>$manifest['requires_php'],'url'=>'https://github.com/'.self::REPO.'/releases/tag/'.$tag,'notes'=>sanitize_textarea_field($release['body']??''),'published_at'=>$release['published_at']??null];
    }
    public static function check(bool $force=false):array {
        $cached=get_site_transient(self::CACHE);if(!$force&&is_array($cached))return $cached;
        $state=['installed'=>DECKA_VERSION,'repository'=>self::REPO,'checked_at'=>gmdate('c'),'status'=>'error','message'=>''];
        try{
            try{$release=self::json('https://api.github.com/repos/'.self::REPO.'/releases/latest');}catch(RuntimeException $e){if(!in_array($e->getCode(),[403,429],true))throw $e;try{return self::from_public_manifest($state);}catch(Throwable $fallback){throw new RuntimeException('Nie można pobrać wydania z GitHub. Spróbuj ponownie później.');}}$manifest_url=null;
            foreach($release['assets']??[] as $a)if(($a['name']??'')==='decka-bilety-update.json')$manifest_url=$a['browser_download_url']??null;
            $tag=$release['tag_name']??'';
            if(!$manifest_url||$manifest_url!=='https://github.com/'.self::REPO.'/releases/download/'.$tag.'/decka-bilety-update.json')throw new RuntimeException('Wydanie nie ma manifestu decka-bilety-update.json.');
            $state+=self::validate($release,self::json($manifest_url,65536));
            $state['status']=version_compare($state['version'],DECKA_VERSION,'>')?'available':'current';
            $state['message']=$state['status']==='available'?'Dostępna nowa wersja '.$state['version'].'.':'Brak nowszego stabilnego wydania.';
        }catch(Throwable $e){$state['status']=$e->getCode()===404?'no_release':'error';$state['message']=$e->getMessage();}
        set_site_transient(self::CACHE,$state,$state['status']==='error'?300:3600);return $state;
    }
    private static function from_public_manifest(array $state):array {
        // Public release assets do not consume the REST API quota shared by hosting IPs.
        $manifest=self::json('https://github.com/'.self::REPO.'/releases/latest/download/decka-bilety-update.json',65536);
        $version=(string)($manifest['version']??'');if(!preg_match('/^\d+\.\d+\.\d+$/',$version))throw new RuntimeException('Niepoprawna wersja manifestu.');
        $tag='v'.$version;$name='decka-bilety-'.$version.'.zip';
        $release=['tag_name'=>$tag,'assets'=>[['name'=>$name,'browser_download_url'=>'https://github.com/'.self::REPO.'/releases/download/'.$tag.'/'.$name]]];
        $state+=self::validate($release,$manifest);$state['status']=version_compare($version,DECKA_VERSION,'>')?'available':'current';$state['message']=$state['status']==='available'?'Dostępna nowa wersja '.$version.'.':'Brak nowszego stabilnego wydania.';$state['source']='public_release';set_site_transient(self::CACHE,$state,3600);return $state;
    }
    public static function status():array {
        $s=get_site_transient(self::CACHE);$s=is_array($s)?$s:['installed'=>DECKA_VERSION,'repository'=>self::REPO,'status'=>'unchecked','checked_at'=>null,'message'=>'Nie sprawdzono jeszcze repozytorium.'];
        $s['installed']=DECKA_VERSION;
        $s['install_url']=current_user_can('update_plugins')&&($s['status']??'')==='available'?add_query_arg(['action'=>'upgrade-plugin','plugin'=>self::file(),'_wpnonce'=>wp_create_nonce('upgrade-plugin_'.self::file())],self_admin_url('update.php')):null;
        $s['manage_url']=current_user_can('update_plugins')?self_admin_url('plugins.php'):null;
        return $s;
    }
    public static function force():array {
        if(!current_user_can('update_plugins'))throw new RuntimeException('Brak uprawnień do sprawdzania aktualizacji wtyczek.');
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        delete_site_transient(self::CACHE);wp_clean_plugins_cache(true);
        $result=self::check(true);
        // Also refresh WordPress.org plugin metadata, without flushing page/object caches.
        wp_update_plugins();
        // Ensure our result is present even if the WordPress.org API is unavailable.
        $t=get_site_transient('update_plugins');if(!is_object($t))$t=new stdClass();$file=self::file();
        $t->checked=(array)($t->checked??[]);$t->checked[$file]=DECKA_VERSION;$t->response=(array)($t->response??[]);$t->no_update=(array)($t->no_update??[]);unset($t->response[$file],$t->no_update[$file]);
        if($result['status']==='available')$t->response[$file]=(object)self::entry($result);
        elseif($result['status']==='current')$t->no_update[$file]=(object)self::entry($result);
        set_site_transient('update_plugins',$t);if(Decka_DB::storage_ready())Decka_DB::audit('update_check',0,$result['status']);
        return self::status()+['wordpress_check_requested'=>true];
    }
    private static function entry(array $s):array{return ['id'=>'https://github.com/'.self::REPO,'slug'=>'decka-bilety','plugin'=>self::file(),'version'=>$s['version'],'new_version'=>$s['version'],'url'=>$s['url'],'package'=>$s['package'],'requires'=>$s['requires'],'requires_php'=>$s['requires_php']];}
    public static function update($update,$data,$file,$locales){if($file!==self::file())return $update;$s=self::check();return in_array($s['status'],['available','current'],true)?self::entry($s):false;}
    public static function information($result,$action,$args){
        if($action!=='plugin_information'||($args->slug??'')!=='decka-bilety')return $result;
        $s=self::check();return (object)['name'=>'Decka Bilety','slug'=>'decka-bilety','version'=>$s['version']??DECKA_VERSION,'author'=>'Decka Pelplin','homepage'=>'https://github.com/'.self::REPO,'requires'=>$s['requires']??'6.6','requires_php'=>$s['requires_php']??'8.2','download_link'=>$s['package']??'','sections'=>['description'=>'Sprzedaż biletów i obsługa wejść Decki Pelplin.','changelog'=>nl2br(esc_html($s['notes']??$s['message']))]];
    }
    public static function download($reply,$package,$upgrader,$extra){
        if($reply!==false)return $reply;
        $prefix='https://github.com/'.self::REPO.'/releases/download/';
        if(!str_starts_with((string)$package,$prefix))return $reply;
        $s=self::check();
        if(!in_array($s['status'],['available','current'],true)||($s['package']??'')!==$package)return new WP_Error('decka_release','Nie można zweryfikować wydania. Sprawdź ponownie aktualizacje.');
        if(version_compare(PHP_VERSION,$s['requires_php'],'<')||version_compare(get_bloginfo('version'),$s['requires'],'<'))return new WP_Error('decka_requirements','Nowa wersja wymaga nowszego WordPressa lub PHP.');
        require_once ABSPATH.'wp-admin/includes/file.php';$file=download_url($package,90);if(is_wp_error($file))return $file;
        if(!hash_equals($s['sha256'],hash_file('sha256',$file))){unlink($file);return new WP_Error('decka_checksum','Suma kontrolna paczki jest nieprawidłowa. Instalacja przerwana.');}
        return $file;
    }
}
