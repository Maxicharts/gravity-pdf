<?php
/*
 * Plugin Name: MaxiCharts Gravity PDF Add-on
 * Plugin URI: https://maxicharts.com
 * Description: Extends MaxiCharts GF : Include graphs directly into exported PDF notification emails
 * Version: 2.0.0
 * Author: Maxicharts
 * Author URI: https://www.termel.fr
 * Text Domain: mcharts_pdf
 * Domain Path: /languages
 */

// to port to windows : use https://codex.wordpress.org/Function_Reference/wp_normalize_path
if (!defined('ABSPATH')) {
    exit();
}

require sprintf("%s/libs/plugin-update-checker-4.4/plugin-update-checker.php", dirname(__FILE__));
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker('https://maxicharts.com/wp-content/uploads/gravity-pdf-add-on-delivery/mcharts_pdf_details.json', __FILE__, 'maxicharts-pdf-add-on');

if (!class_exists('MAXICHARTSAPI')) {
    define('MAXICHARTS_PLUGIN_PATH', plugin_dir_path(__DIR__));
    $toInclude = MAXICHARTS_PLUGIN_PATH . '/maxicharts/mcharts_utils.php';
    if (file_exists($toInclude)) {
        include_once($toInclude);
    }
}

require_once 'vendor/autoload.php';

//use JonnyW\PhantomJs\Client;

use Browser\Casper;

if (!class_exists('maxicharts_gravity_pdf')) {

    class maxicharts_gravity_pdf
    {

        private $directoryName = 'maxicharts_images';

        private static $renderPageName = 'maxicharts-pdf-render';

        private static $tmpTemplateName = 'maxicharts-template.php';

        protected $images_abs_path = null;

        protected $casper = null;

        private $options;

        private $viewportWidth = 1920;

        private $viewportHeight = 1080;

        function __construct()
        {
            if (!class_exists('MAXICHARTSAPI')) {
                $msg = __('Please install MaxiCharts before');
                return $msg;
            }

            $this->getOptions();

            add_action('admin_menu', array($this, 'add_plugin_page'));
            add_action('admin_init', array($this, 'page_init'));

            add_filter('gfpdf_pdf_field_content', array(
                $this,
                'replace_maxicharts_shortcodes'
            ), 10, 4);

            add_filter('shortcode_atts_maxicharts', array(
                $this,
                'filter_shortcode_atts'
            ));
            add_filter('shortcode_atts_gfchartsreports', array(
                $this,
                'filter_shortcode_atts'
            ));

            // Filter page template
            /*
             * add_filter('page_template', array(
             * $this,
             * 'catch_plugin_template'
             * ));
             */
            if ($this->images_abs_path == null) {
                $this->createImagesDirectory();
            }

            if (is_writable($this->images_abs_path)) {
                self::getLogger()->debug("Folder writable : " . $this->images_abs_path);
            } else {
                self::getLogger()->error("Folder not writable : " . $this->images_abs_path);
            }

            self::getLogger()->debug("Images dir successfully created");

            try {

                self::getLogger()->debug("Make sure phantomjs and casperjs executable...");

                // check if PHP safe mode enabled
                self::getLogger()->info('Current PHP version : ' . phpversion());

                if (ini_get('safe_mode')) {
                    // safe mode is on
                    self::getLogger()->error('Safe mode enabled: may cause problems executing casperjs / phantomjs');
                } else {
                    // it's not
                    self::getLogger()->info('no safe mode enabled');
                }

                // FIXME : need to make sure phantomjs installed and path to exe available
                // locate phantomJs exe
                $path_to_phantomjs_exe = $this->options['phantomjs_path'];
                if ($path_to_phantomjs_exe && is_executable($path_to_phantomjs_exe)) {
                    $phantomjs_path = dirname($path_to_phantomjs_exe);

                    self::getLogger()->info("PhantomJS installed here: " . $path_to_phantomjs_exe);
                    putenv('PATH=' . getenv('PATH') . ':' . $phantomjs_path);
                    $system_path = exec('echo $PATH');
                    self::getLogger()->info("PATH set to: " . $system_path);

                    putenv('PHANTOMJS_EXECUTABLE=' . $path_to_phantomjs_exe);
                } else {
                    if ($this->is_executable_pathenv("phantomjs")) {
                        self::getLogger()->info("PhantomJS installed and inside path, ready to work.");
                        $system_path = exec('echo $PATH');
                        self::getLogger()->info("PATH set to: " . $system_path);
                        $which_command = "which phantomjs";
                        //self::getLogger()->debug($which_command);
                        $path_to_phantomjs_exe = exec($which_command);
                        putenv('PHANTOMJS_EXECUTABLE=' . $path_to_phantomjs_exe);
                    } else {

                        $which_command = "which phantomjs";
                        self::getLogger()->info($which_command);
                        $path_to_phantomjs_exe = exec($which_command);
                        $path_to_phantomjs_exe = '/usr/local/bin/phantomjs';

                        if (empty($path_to_phantomjs_exe)) {
                            $path_to_phantomjs_exe_file = exec("command -v phantomjs");
                            self::getLogger()->info($path_to_phantomjs_exe_file);
                            $path_to_phantomjs_exe = dirname($path_to_phantomjs_exe_file);
                            self::getLogger()->info($path_to_phantomjs_exe);
                        }
                        if (is_executable($path_to_phantomjs_exe)) {
                            // phantomjs exe exists
                            self::getLogger()->info("PhantomJS installed here: " . $path_to_phantomjs_exe);
                            // export PATH=$PATH:/Users/Tom/Downloads/phantomjs-1.9.2/bin
                            // add to path

                            putenv('PATH=' . getenv('PATH') . ':' . $path_to_phantomjs_exe);
                            $system_path = exec('echo $PATH');
                            self::getLogger()->info("PATH set to: " . $system_path);

                            putenv('PHANTOMJS_EXECUTABLE=' . $path_to_phantomjs_exe);
                        } else {
                            self::getLogger()->error("No phantomjs executable found, is it installed ? are permissions ok ? chmod +x ?");
                            self::getLogger()->error($path_to_phantomjs_exe);
                        }
                    }
                }

                $tmpCasperDir = trailingslashit(realpath(dirname(__FILE__) . '/tmp'));
                if (!is_dir($tmpCasperDir)) {
                    self::getLogger()->error("No Tmp CasperJS dir");

                    self::getLogger()->error($tmpCasperDir);
                }

                //self::getLogger()->info("Init Casper with exe : " . $casperJSExecutable);
                self::getLogger()->info("Init Casper with tmp : " . $tmpCasperDir);



                $path_to_casperjs_exe = $this->options['casperjs_path'];
                self::getLogger()->fatal("testing Casperjs at " . $path_to_casperjs_exe);
                if ($path_to_casperjs_exe && is_executable($path_to_casperjs_exe)) {
                    $casperjs_path = dirname($path_to_casperjs_exe);

                    self::getLogger()->fatal("Casperjs installed and executale.");

                    putenv('PATH=' . getenv('PATH') . ':' . $casperjs_path);
                    $system_path = exec('echo $PATH');
                    self::getLogger()->fatal("PATH set to: " . $system_path);

                    $this->casper = new Casper(trailingslashit($casperjs_path)); // $casperJSExecutable/*, $tmpCasperDir*/);
                } else {

                    $which_command = "which casperjs";
                    self::getLogger()->debug($which_command);
                    $path_to_casperjs_exe = exec($which_command);
                    $path_to_casperjs_exe = '/usr/local/lib/node_modules/casperjs';
                    // $path_to_casperjs_exe = '/usr/local/bin/casperjs';

                    if (empty($path_to_casperjs_exe)) {
                        $path_to_casperjs_exe = exec("command -v casperjs");
                    }

                    $casperjs_path = dirname($path_to_casperjs_exe);

                    self::getLogger()->warn($path_to_casperjs_exe);
                    self::getLogger()->warn($casperjs_path);
                    if ($this->is_executable_pathenv("casperjs")) {
                        self::getLogger()->info("Casperjs installed and executale.");
                        $system_path = exec('echo $PATH');
                        self::getLogger()->info("PATH set to: " . $system_path);

                        $this->casper = new Casper(trailingslashit($casperjs_path)); // $casperJSExecutable/*, $tmpCasperDir*/);
                    } else {
                        self::getLogger()->warn("No casperjs executable found, is it installed ? are permissions ok ? chmod +x ?");

                        if (is_executable(trailingslashit($path_to_casperjs_exe) . 'casperjs')) {
                            self::getLogger()->info("casperjs exe exists and executable");

                            self::getLogger()->info("CASPER JS installed here: " . $path_to_casperjs_exe);

                            // add to path

                            putenv('PATH=' . getenv('PATH') . ':' . $casperjs_path);
                            $system_path = exec('echo $PATH');
                            self::getLogger()->info("PATH set to: " . $system_path);

                            $this->casper = new Casper(trailingslashit($casperjs_path));
                        } else {
                            self::getLogger()->warn("No executable casperjsfound here " . $path_to_casperjs_exe);
                        }
                    }
                }

                if ($this->is_executable_pathenv("casperjs")) {
                    self::getLogger()->info("Casperjs installed and executable.");
                } else {
                    self::getLogger()->fatal("No Casperjs installed and executable.");
                    return;
                }

                // https://github.com/ariya/phantomjs/issues/14376
                // export QT_QPA_PLATFORM=offscreen
                putenv("QT_QPA_PLATFORM=offscreen");
                $system_var = exec('echo $QT_QPA_PLATFORM');
                self::getLogger()->info("QT_QPA_PLATFORM set to: " . $system_var);
                /*
                // export QT_QPA_PLATFORM=offscreen
                $system_export = exec('export QT_QPA_PLATFORM=offscreen');
                self::getLogger()->info($system_export);
                */
                // forward options to phantomJS
                // for example to ignore ssl errors
                $this->casper->setOptions([
                    'ignore-ssl-errors' => 'yes',
                    'QT_QPA_PLATFORM' => 'offscreen',
                ]);

                // self::getLogger()->info($this->casper->path2casper);

                self::getLogger()->info("Casper js instanciated");
                self::getLogger()->debug($this->casper);
            } catch (Exception $e) {
                self::getLogger()->fatal("Exception initializing CasperJS");
                self::getLogger()->fatal($e->getMessage());
            }

            self::getLogger()->debug(__CLASS__ . ' class constructed');
        }

        function is_executable_pathenv(string $filename): bool
        {
            if (is_executable($filename)) {
                return true;
            }
            if ($filename !== basename($filename)) {
                return false;
            }
            $paths = explode(PATH_SEPARATOR, getenv("PATH"));
            foreach ($paths as $path) {
                if (is_executable($path . DIRECTORY_SEPARATOR . $filename)) {
                    return true;
                }
            }
            return false;
        }

        // setup a function to check if these pages exist
        static function the_slug_exists($post_name)
        {
            global $wpdb;
            if ($wpdb->get_row("SELECT post_name FROM wp_posts WHERE post_name = '" . $post_name . "'", 'ARRAY_A')) {
                return true;
            } else {
                return false;
            }
        }

        static function createRenderPageAndCopySpecificTemplate()
        {
            if (!class_exists('MAXICHARTSAPI')) {
                $msg = __('Please install MaxiCharts before');
                return $msg;
            }

            self::getLogger()->debug("########## createRenderPageAndCopySpecificTemplate");
            // programmatically create some basic pages, and then set Home and Blog
            $postSlug = self::$renderPageName;
            // $tmpTemplateFile = self::$tmpTemplateName;
            $pageCreated = false;
            $templateCopied = false;
            // create the blog page
            if (/*isset($_GET['activated']) && */is_admin()) {
                $blog_page_title = $postSlug;
                $blog_page_content = 'maxicharts';
                $blog_page_check = get_page_by_title($blog_page_title);
                $blog_page = array(
                    'post_type' => 'page',
                    'post_title' => $blog_page_title,
                    'post_content' => $blog_page_content,
                    'post_status' => 'publish',
                    'post_author' => 1,
                    'post_slug' => $postSlug
                );
                if (!isset($blog_page_check->ID) && !self::the_slug_exists($postSlug)) {
                    $blog_page_id = wp_insert_post($blog_page);

                    self::getLogger()->info("Page " . $blog_page_title . " created with id : " . $blog_page_id);
                } else {
                    $blog_page_id = $blog_page_check->ID;
                    self::getLogger()->debug("Page " . $blog_page_title . " alreay exists with id : " . $blog_page_check->ID);
                }

                $pageCreated = true;
            } else {
                self::getLogger()->error("Not an admin page...");
            }

            self::getLogger()->debug("########## end createRenderPageAndCopySpecificTemplate #### page id " . $blog_page_id);
        }

        function filter_shortcode_atts($atts)
        {
            self::getLogger()->debug('filter_shortcode_atts');
            $modified_atts = array();
            self::getLogger()->debug($atts);
            $modified_atts = array_map(function ($value) {

                return str_replace('&quot;', '', $value);
            }, $atts);
            self::getLogger()->debug($modified_atts);
            return $modified_atts;
        }

        function createImagesDirectory()
        {
            $upload = wp_upload_dir();
            $upload_dir = $upload['basedir'];
            $upload_dir = $upload_dir . '/' . $this->directoryName;
            $this->images_abs_path = $upload_dir;
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755);
                self::getLogger()->info('+++ Image directory created : ' . $this->images_abs_path);
            } else {
                self::getLogger()->debug('+++ Image directory already exists : ' . $this->images_abs_path);
                chmod($upload_dir, 0755);
            }

            if (is_writable($upload_dir)) {
                self::getLogger()->debug("Folder writable : " . $upload_dir);
            } else {
                self::getLogger()->error("Folder not writable : " . $upload_dir);
            }
        }

        function getRenderPageId()
        {
            $wpPost = get_page_by_title(self::$renderPageName);
            if ($wpPost) {
                return $wpPost->ID;
            } else {
                return -1;
            }
        }

        function getRenderPage()
        {
            $inputUrl = null;
            $renderPageTitle = self::$renderPageName;
            $wpPost = get_page_by_title($renderPageTitle);
            if ($wpPost) {

                $inputUrl = get_permalink($wpPost);
                self::getLogger()->info("Render page exists " . $inputUrl);
            } else {
                self::getLogger()->error("No render page " . $renderPageTitle . ", please re-install plugin");
            }

            return $inputUrl;
        }

        function updatePageTagWithCurrentShortcode($page_id, $shortcode)
        {
            if (!is_numeric($page_id)) {
                self::getLogger()->error("No page ID");
                self::getLogger()->error($shortcode);
            }
            $post = array(
                'ID' => $page_id,
                'post_content' => $shortcode
            );
            $upmRes = wp_update_post($post);
            if ($upmRes) {
                self::getLogger()->info("Post content updated " . $blog_page_title);
            } else {
                self::getLogger()->error("wp_update_post page : " . $blog_page_id);
                self::getLogger()->error($upmRes);
            }
        }

        function getImageFromWebRender($shortcode)
        {
            $fullImagePath = null;
            self::getLogger()->debug('Render page with shortcode : ' . $shortcode);
            $inputUrl = $this->getRenderPage();
            if (!$inputUrl) {
                self::getLogger()->error("No render page");
                return $fullImagePath;
            }
            $use = 'casper';

            // convert simple quote html into real simple quotes
            $cleanedShortcode = htmlspecialchars_decode($shortcode, ENT_QUOTES);
            self::getLogger()->debug('Cleaned shortcode : ' . $cleanedShortcode);
            self::getLogger()->debug('Page id : ' . $this->getRenderPageId());
            $this->updatePageTagWithCurrentShortcode($this->getRenderPageId(), $cleanedShortcode);

            self::getLogger()->debug('Injected on page : ' . $this->getRenderPageId());

            $filename = uniqid('mc-gpdf-');
            $filename .= '.png';

            switch ($use) {
                case 'php-phantomjs':
                    self::getLogger()->debug('getImageFromWebRender');
                    $width = 800;
                    $height = 600;
                    $top = 0;
                    $left = 0;

                    self::getLogger()->debug("Will request page:");
                    self::getLogger()->debug($inputUrl);
                    $request = $this->client->getMessageFactory()->createCaptureRequest($inputUrl, 'GET');

                    self::getLogger()->debug($filename);
                    $fullImagePath = $this->images_abs_path . '/' . $filename;
                    self::getLogger()->debug($fullImagePath);
                    $request->setOutputFile($fullImagePath);
                    $request->setViewportSize($width, $height);
                    $request->setCaptureDimensions($width, $height, $top, $left);

                    self::getLogger()->debug($request);

                    $response = $this->client->getMessageFactory()->createResponse();
                    self::getLogger()->debug($response);

                    try {
                        // Send the request
                        $this->client->send($request, $response);
                    } catch (Exception $e) {
                        self::getLogger()->debug("####### ERROR #######");
                        self::getLogger()->error($e->getMessage());
                        return null;
                    }

                    self::getLogger()->debug($response->getConsole());
                    self::getLogger()->debug($sendReturn);
                    if ($response->getStatus() === 200) {
                        self::getLogger()->debug($response->getContent());
                    }
                    self::getLogger()->debug($this->client->getLog()); // String
                    self::getLogger()->debug('Request sent : ' . $fullImagePath);

                    break;

                case 'casper':
                    /*
                    putenv("QT_QPA_PLATFORM=offscreen");
                    self::getLogger()->debug(getenv("QT_QPA_PLATFORM"));
                    */
                    $this->casper->start($inputUrl);
                    $this->casper->setViewPort($this->viewportWidth, $this->viewportHeight);
                    $fullImagePath = trailingslashit($this->images_abs_path) . $filename;

                    $selector = 'canvas.maxicharts_reports_canvas';
                    self::getLogger()->debug("Will try to capture " . $selector . " to file : " . $fullImagePath);
                    $this->casper->waitForSelector($selector, 5000);
                    $this->casper->captureSelector($selector, $fullImagePath);

                    try {
                        $this->casper->run();
                    } catch (Exception $e) {
                        self::getLogger()->error("####### ERROR #######");
                        self::getLogger()->error($e->getMessage());
                        self::getLogger()->error("*** CASPER DEBUG ***");
                        // check the urls casper get through
                        self::getLogger()->error($this->casper->getRequestedUrls());
                        // need to debug? just check the casper output
                        self::getLogger()->error($this->casper->getOutput());

                        $fullImagePath = null;
                    }

                    self::getLogger()->debug("*** CASPER DEBUG ***");
                    // check the urls casper get through
                    self::getLogger()->debug($this->casper->getRequestedUrls());
                    // need to debug? just check the casper output
                    self::getLogger()->debug($this->casper->getOutput());
                    self::getLogger()->debug("--- end of casper run -----");
                    break;
            }

            if (file_exists($fullImagePath)) {
                self::getLogger()->debug("Image successfully captured and written : " . $fullImagePath);
            } else {
                self::getLogger()->error("Image not written: " . $fullImagePath);
            }

            return $fullImagePath;
        }

        function replace_maxicharts_shortcodes($value, $field, $entry, $form)
        {
            $fieldID = $field->id;
            $result = $value;
            self::getLogger()->debug('------- replace_maxicharts_shortcodes::Filtering ' . $fieldID . ' val ' . $value . ' ---------');

            $shortcodeLookup = 'gfchartsreports';
            $decoded = trim(html_entity_decode($value));
            // check if special class found : maxicharts-pdf
            if (has_shortcode($decoded, $shortcodeLookup)) {
                self::getLogger()->info($shortcodeLookup . ' shortcode found');
                self::getLogger()->info('##########################################');
                self::getLogger()->info('###### ' . $decoded . ' ######');
                self::getLogger()->info('##########################################');

                // if found, get field id
                self::getLogger()->info('Found shortoode ! field id = ' . $fieldID);

                // execute shortcode in phantom browser
                $fullImagePath = $this->getImageFromWebRender($decoded);
                /*
                 * if (file_exists($fullImagePath)) {
                 * self::getLogger()->debug("Image successfully captured and written : " . $fullImagePath);
                 * } else {
                 * self::getLogger()->error("Image not written: " . $fullImagePath);
                 * }
                 */
                if ($fullImagePath == null || !file_exists($fullImagePath)) {
                    self::getLogger()->error('No image written: ' . $fullImagePath);
                    /*
                     * if (class_exists('MAXICHARTSAPI')) {
                     * $dir = plugin_dir_path ( MAXICHARTSAPI::__FILE__ );
                     * } else {
                     *
                     * }
                     * $today = date("Y-m-d"); // 20010310
                     * $logpath = trailingslashit($dir . 'logs');
                     * $logfilename = 'maxicharts-' . $today . '.log';
                     * $logFilesPath = $logpath . $logfilename;
                     */
                    $result = "Oups, something went wrong... Please inspect log file <a href=\"$logFilesPath\" target=\"_blank\">$logfilename</a>";
                    // return $result;
                } else {
                    self::getLogger()->info('Full img path : ' . $fullImagePath);

                    // export graph as png image
                    $upload = wp_upload_dir();
                    $upload_dir = $upload['basedir'];
                    $upload_url = $upload['baseurl'];
                    $fullImgUrl = str_replace($upload_dir, $upload_url, $fullImagePath);
                    self::getLogger()->info('Full img URL : ' . $fullImgUrl);
                    // compress image
                    $compression = false;
                    if ($compression) {
                        $compressedImgUrl = $this->getCompressedImagePathFromUrl($fullImgUrl, $this->directoryName);
                    } else {
                        $compressedImgUrl = $fullImgUrl;
                    }
                    self::getLogger()->info('After compression : ' . $compressedImgUrl);
                    // include into PDF before export

                    $imageTag = '<img src="' . $compressedImgUrl . '">';
                    self::getLogger()->info('HTML tag : ' . $imageTag);

                    $result = $imageTag;
                }
            } else {
                self::getLogger()->debug('no ' . $shortcodeLookup . ' shortcode found');
                // $result = $value;
            }

            return $result;
        }

        static function getLogger()
        {
            if (class_exists('MAXICHARTSAPI')) {
                return MAXICHARTSAPI::getLogger('GPDF');
            } else {
                $msg = __('Need to install MaxiCharts');
            }
        }

        // compression functions ---------------------------------------------------------------------------
        function getCompressedImagePathFromUrl($imageUrl, $uploadFolder = null)
        {
            $imgPath = '';
            $customFolderPos = false;
            self::getLogger()->info($imageUrl);

            $wpcontentPos = stripos($imageUrl, 'wp-content/upload');
            if ($uploadFolder) {
                $customFolderPos = stripos($imageUrl, $uploadFolder);
            }
            if ($customFolderPos !== false) {
                self::getLogger()->info('MAXICHART_PDF::' . $customFolderPos);
                $upload_root = $this->images_abs_path;

                $pattern = '/^(.*?)\/' . $uploadFolder . '/';
                // $pattern = '|^(.*?)/maxicharts_images/|';
                self::getLogger()->info($customFolderPos . ' :: ' . $pattern . ' :: ' . $upload_root . ' :: ' . $imageUrl);
                $path_to_uncompressed_file = realpath(preg_replace($pattern, $upload_root, $imageUrl));
                // self::getLogger()->info ( 'path to uncompressed File::' . $path_to_uncompressed_file);
            } else if ($wpcontentPos !== false) {
                self::getLogger()->info('WP::' . $wpcontentPos);
                $path_to_uncompressed_file = realpath(get_template_directory_uri() . substr($imageUrl, $wpcontentPos));
            } else {
                self::getLogger()->error('getCompressedImagePathFromUrl::unknown type');
            }
            // $path_to_uncompressed_file = $patientImg;
            $path_to_compressed_file = self::setCompressedFileName($path_to_uncompressed_file);

            self::getLogger()->info($path_to_uncompressed_file);
            self::getLogger()->info($path_to_compressed_file);
            // this will ensure that $path_to_compressed_file points to compressed file
            // and avoid re-compressing if it's been done already

            if (!file_exists($path_to_compressed_file)) {
                $imgFormat = exif_imagetype($path_to_uncompressed_file);
                if (IMAGETYPE_PNG == $imgFormat) {
                    try {
                        file_put_contents($path_to_compressed_file, self::compress_png($path_to_uncompressed_file));
                        self::getLogger()->info("compressed! " . $path_to_compressed_file);
                    } catch (Exception $e) {
                        self::getLogger()->error($e->getMessage());
                        return "Exception reçue : " . $e->getMessage();
                    }
                } else {
                    self::getLogger()->error("unknown image format : " . $imgFormat);
                }
            }

            if (file_exists($path_to_compressed_file) && is_readable($path_to_compressed_file)) {
                $imgPath = $path_to_compressed_file;
            } else {
                $imgPath = $path_to_uncompressed_file;
            }

            return $imgPath;
        }

        static function setCompressedFileName($uncompressedFilename)
        {
            return preg_replace('/(\.gif|\.jpg|\.png)/', '_thumb$1', $uncompressedFilename);
        }

        /**
         * Optimizes PNG file with pngquant 1.8 or later (reduces file size of 24-bit/32-bit PNG images).
         *
         * You need to install pngquant 1.8 on the server (ancient version 1.0 won't work).
         * There's package for Debian/Ubuntu and RPM for other distributions on http://pngquant.org
         *
         * @param $path_to_png_file string
         *            - path to any PNG file, e.g. $_FILE['file']['tmp_name']
         * @param $max_quality int
         *            - conversion quality, useful values from 60 to 100 (smaller number = smaller file)
         * @return string - content of PNG file after conversion
         */
        static function compress_png($path_to_png_file, $max_quality = 90)
        {
            if (!file_exists($path_to_png_file)) {
                throw new Exception("compress_png::File does not exist: $path_to_png_file");
            }

            // guarantee that quality won't be worse than that.
            $min_quality = 60;

            // '-' makes it use stdout, required to save to $compressed_png_content variable
            // '<' makes it read from the given file path
            // escapeshellarg() makes this safe to use with any path
            $compressed_png_content = shell_exec("pngquant --quality=$min_quality-$max_quality - < " . escapeshellarg($path_to_png_file));

            if (!$compressed_png_content) {
                throw new Exception("compress_png::Conversion to compressed PNG failed. Is pngquant 1.8+ installed on the server?");
            }

            return $compressed_png_content;
        }
        // end of compression functions ---------------------------------------------------------------------------


        /**
         * Add options page
         */


        public function getOptions()
        {
            $this->options = get_option('maxicharts_gravity_pdf_addon_option');
            return $this->options;
        }



        public function add_plugin_page()
        {
            // This page will be under "Settings"
            add_options_page(
                'Settings Admin',
                'MaxiCharts Gravity PDF Addon',
                'manage_options',
                'maxicharts-gravity-pdf-addon-setting-admin',
                array($this, 'create_admin_page')
            );
        }

        /**
         * Options page callback
         */
        public function create_admin_page()
        {
            // Set class property
            $this->options = get_option('maxicharts_gravity_pdf_addon_option');
?>
<div class="wrap">
    <h1>MaxiCharts Gravity PDF Addon Settings</h1>
    <form method="post" action="options.php">
        <?php
                    // This prints out all hidden setting fields
                    settings_fields('maxicharts_gravity_pdf_addon_option_group');
                    do_settings_sections('maxicharts-gravity-pdf-addon-setting-admin');
                    submit_button();
                    ?>
    </form>
</div>
<?php
        }

        /**
         * Register and add settings
         */
        public function page_init()
        {
            register_setting(
                'maxicharts_gravity_pdf_addon_option_group', // Option group
                'maxicharts_gravity_pdf_addon_option', // Option name
                array($this, 'sanitize')
            ); // Sanitize

            add_settings_section(
                'maxicharts_gravity_pdf_addon_setting_section_id', // ID
                'Settings', // Title
                array($this, 'print_section_info'), // Callback
                'maxicharts-gravity-pdf-addon-setting-admin'
            );


            add_settings_field(
                'casperjs_path',
                'casperjs path',
                array($this, 'casperjs_path_callback'),
                'maxicharts-gravity-pdf-addon-setting-admin',
                'maxicharts_gravity_pdf_addon_setting_section_id'
            );

            add_settings_field(
                'phantomjs_path',
                'phantomjs path',
                array($this, 'phantomjs_path_callback'),
                'maxicharts-gravity-pdf-addon-setting-admin',
                'maxicharts_gravity_pdf_addon_setting_section_id'
            );
        }

        public function print_section_info()
        {
            print
                __(
                    ''
                );
        }

        public function sanitize($input)
        {
            $new_input = array();

            if (isset($input['casperjs_path']))
                $new_input['casperjs_path'] = wp_kses_post($input['casperjs_path']);

            if (isset($input['phantomjs_path']))
                $new_input['phantomjs_path'] = wp_kses_post($input['phantomjs_path']);


            return $new_input;
        }


        public function casperjs_path_callback()
        {
            $defaultTag = '';
            printf(
                '<input class="widefat" type="text" id="casperjs_path" name="maxicharts_gravity_pdf_addon_option[casperjs_path]" value="%s" />',
                isset($this->options['casperjs_path']) ? esc_attr($this->options['casperjs_path']) : $defaultTag
            );
        }
        public function phantomjs_path_callback()
        {
            $defaultTag = '';
            printf(
                '<input class="widefat" type="text" id="phantomjs_path" name="maxicharts_gravity_pdf_addon_option[phantomjs_path]" value="%s" />',
                isset($this->options['phantomjs_path']) ? esc_attr($this->options['phantomjs_path']) : $defaultTag
            );
        }
    }
}

new maxicharts_gravity_pdf();
register_activation_hook(__FILE__, array(
    'maxicharts_gravity_pdf',
    'createRenderPageAndCopySpecificTemplate'
));