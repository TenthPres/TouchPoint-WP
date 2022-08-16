<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use WP_Post;
use ZipArchive;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Admin API class.
 */
class TouchPointWP_AdminAPI implements api {

    public const API_ENDPOINT_SCRIPTZIP = "scriptzip";

    /**
     * Constructor function
     */
    public function __construct() {
//        add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 1 );
    }

    /**
     * Handle API requests
     *
     * @param array $uri The request URI already parsed by parse_url()
     *
     * @return bool False if endpoint is not found.  Should print the result.
     */
    public static function api(array $uri): bool
    {
        switch (strtolower($uri['path'][2])) {
            case "memtypes":
                $divs = explode(",", $_GET['divs']);
                $mt = TouchPointWP::instance()->getMemberTypesForDivisions($divs);
                echo json_encode($mt);
                exit;

            case self::API_ENDPOINT_SCRIPTZIP:
                if (!TouchPointWP::currentUserIsAdmin()) {
                    return false;
                }
                if (!TouchPointWP::instance()->admin()->generateAndEchoPython()) {
                    // something went wrong...
                    return false;
                }
                exit;

            case "scriptupdate":
                if (!TouchPointWP::currentUserIsAdmin()) {
                    return false;
                }
                try {
                    TouchPointWP::instance()->settings->updateDeployedScripts();
                    echo "Success";
                } catch (TouchPointWP_Exception $e) {
                    echo "Failed: " . $e->getMessage();
                }
                exit;

            case "force-migrate":
                if (!TouchPointWP::currentUserIsAdmin()) {
                    return false;
                }
                TouchPointWP::instance()->settings->migrate();
                exit;
        }

        return false;
    }

    /**
     * Generate scripts package and send to client.
     *
     * There needs to be a permission check elsewhere, before this method is called.
     *
     * @return bool True on success, False on failure.
     */
    private function generateAndEchoPython(): bool
    {
        try {
            $fileName = $this->generatePython(true);
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
        header("Content-disposition: attachment; filename=TouchPoint-WP-Scripts.zip");
        header('Content-type: application/zip');

        readfile($fileName);
        unlink($fileName);
        return true;
    }

    /**
     * Generate HTML for displaying fields.
     *
     * @param array    $data Data array.
     * @param ?WP_Post $post Post object.
     * @param bool     $echo Whether to echo the field HTML or return it.
     *
     * @return string
     */
    public function displayField(array $data = [], ?WP_Post $post = null, bool $echo = true ): string
    {
        // Get field info.
        $field = $data['field'] ?? $data;

        // Check for prefix on option name.
        $option_name = '';
        if ( isset( $data['prefix'] ) ) {
            $option_name = $data['prefix'];
        }

        // Get saved data.
        $data = '';
        $option_name .= $field['id'];

        if ( $post ) {
            // Get saved field data.
            $option       = get_post_meta( $post->ID, $field['id'], true );
        } else {
            // Get saved option.
            $option       = get_option($option_name); // TODO MULTI
        }

        // Get data to display in field.
        if ( isset( $option ) ) {
            $data = $option;
        }

        // Show default data if no option saved and default is supplied.
        if ( false === $data && isset( $field['default'] ) ) {
            $data = $field['default'];
        } elseif ( false === $data ) {
            $data = '';
        }

        $html = '';

        // if field is hidden, hide!
        if (array_key_exists('hidden', $field) && $field['hidden']) {
            $html .= "<div style=\"display:none\">";
        }

        if (isset($field['formClass'])) {
            $class = $field['formClass'];
            $html .= "<div class=\"$class\">";
        }

        switch ( $field['type'] ) {
            case 'text':
            case 'url':
            case 'email':
                $html .= '<input id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $option_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $data ) . '" />' . "\n";
                break;

            case 'password':
            case 'number':
            case 'hidden':
                $min = '';
                if ( isset( $field['min'] ) ) {
                    $min = ' min="' . esc_attr( $field['min'] ) . '"';
                }

                $max = '';
                if ( isset( $field['max'] ) ) {
                    $max = ' max="' . esc_attr( $field['max'] ) . '"';
                }
                $html .= '<input id="' . esc_attr( $field['id'] ) .
                         '" type="' . esc_attr( $field['type'] ) .
                         '" name="' . esc_attr( $option_name ) .
                         (array_key_exists('placeholder', $field) ? '" placeholder="' . esc_attr( $field['placeholder'] ) : "") .
                         '" value="' . esc_attr( $data ) .
                         '"' . $min . $max . '/>' . "\n";
                break;

            case 'text_secret':
                $html .= '<input id="' . esc_attr( $field['id'] ) . '" type="text" name="' . esc_attr( $option_name ) .
                         (array_key_exists('placeholder', $field) ? '" placeholder="' . esc_attr( $field['placeholder'] ) : "") .
                         '" value="" />' . "\n";
                break;

            case 'textarea':
                $html .= '<textarea id="' . esc_attr( $field['id'] ) . '" rows="5" cols="50" name="' . esc_attr( $option_name ) .
                         (array_key_exists('placeholder', $field) ? '" placeholder="' . esc_attr( $field['placeholder'] ) : "") .
                         '">' . $data . '</textarea><br/>' . "\n";
                break;

            case 'checkbox':
                $checked = '';
                if ('on' === $data) {
                    $checked = 'checked="checked"';
                }
                $html .= '<input id="' . esc_attr( $field['id'] ) . '" type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $option_name ) . '" ' . $checked . '/>' . "\n";
                break;

            case 'checkbox_multi':
                foreach ( $field['options'] as $k => $v ) {
                    $checked = false;
                    if ( in_array( $k, (array) $data, true ) ) {
                        $checked = true;
                    }
                    $html .= '<p><label for="' . esc_attr( $field['id'] . '_' . $k ) . '" class="checkbox_multi"><input type="checkbox" ' . checked( $checked, true, false ) . ' name="' . esc_attr( $option_name ) . '[]" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . $v . '</label></p> ';
                }
                break;

            case 'radio':
                foreach ( $field['options'] as $k => $v ) {
                    $checked = false;
                    if ( $k === $data ) {
                        $checked = true;
                    }
                    $html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><input type="radio" ' . checked( $checked, true, false ) . ' name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" /> ' . $v . '</label> ';
                }
                break;

            case 'select':
                $html .= '<select name="' . esc_attr( $option_name ) . '" id="' . esc_attr( $field['id'] ) . '">';
                foreach ( $field['options'] as $k => $v ) {
                    $selected = false;
                    if ( $k === $data ) {
                        $selected = true;
                    }
                    $html .= '<option ' . selected( $selected, true, false ) . ' value="' . esc_attr( $k ) . '">' . $v . '</option>';
                }
                $html .= '</select> ';
                break;

            case 'select_grouped':
                $html .= '<select name="' . esc_attr( $option_name ) . '" id="' . esc_attr( $field['id'] ) . '">';
                foreach ( $field['options'] as $grp => $opts ) {
                    $html .= '<optgroup label="' . esc_attr($grp) . '">';
                    foreach ( $opts as $k => $v ) {
                        $selected = false;
                        if ( $k === $data ) {
                            $selected = true;
                        }
                        $html .= '<option ' . selected( $selected, true, false ) . ' value="' . esc_attr( $k ) . '">' . $v . '</option>';
                    }
                    $html .= '</optgroup>';
                }
                $html .= '</select> ';
                break;

            case 'select_multi':
                $html .= '<select name="' . esc_attr( $option_name ) . '[]" id="' . esc_attr( $field['id'] ) . '" multiple="multiple">';
                foreach ( $field['options'] as $k => $v ) {
                    $selected = false;
                    if ( in_array( $k, (array) $data, true ) ) {
                        $selected = true;
                    }
                    $html .= '<option ' . selected( $selected, true, false ) . ' value="' . esc_attr( $k ) . '">' . $v . '</option>';
                }
                $html .= '</select> ';
                break;

//            case 'image':
//                $image_thumb = '';
//                if ( $data ) {
//                    $image_thumb = wp_get_attachment_thumb_url( $data );
//                }
//                $html .= '<img id="' . $option_name . '_preview" class="image_preview" src="' . $image_thumb . '" /><br/>' . "\n";
//                $html .= '<input id="' . $option_name . '_button" type="button" data-uploader_title="' . __( 'Upload an image', 'wordpress-plugin-template' ) . '" data-uploader_button_text="' . __( 'Use image', 'wordpress-plugin-template' ) . '" class="image_upload_button button" value="' . __( 'Upload new image', 'wordpress-plugin-template' ) . '" />' . "\n";
//                $html .= '<input id="' . $option_name . '_delete" type="button" class="image_delete_button button" value="' . __( 'Remove image', 'wordpress-plugin-template' ) . '" />' . "\n";
//                $html .= '<input id="' . $option_name . '" class="image_data_field" type="hidden" name="' . $option_name . '" value="' . $data . '"/><br/>' . "\n";
//                break;
//
//            case 'color':
//                //phpcs:disable
//                ?><!--<div class="color-picker" style="position:relative;">-->
<!--                <input type="text" name="--><?php //esc_attr_e( $option_name ); ?><!--" class="color" value="--><?php //esc_attr_e( $data ); ?><!--" />-->
<!--                <div style="position:absolute;background:#FFF;z-index:99;border-radius:100%;" class="colorpicker"></div>-->
<!--                </div>-->
<!--                --><?php
//                //phpcs:enable
//                break;

            case 'editor':
                wp_editor(
                    $data,
                    $option_name,
                    array(
                        'textarea_name' => $option_name,
                    )
                );
                break;

        }


        if (isset($field['formClass'])) {
            $html .= "</div>";
        }


        $description = null;
        if (array_key_exists('description', $field)) {
            $description = $field['description'];

            if (is_callable($description)) {
                $description = call_user_func($description);
            }
        }
        switch ( $field['type'] ) {
            case 'checkbox_multi':
            case 'radio':
            case 'select_multi':
                if ($description !== null && (!array_key_exists('hidden', $field) || !$field['hidden'])) {
                    $html .= '<br/><span class="description">' . $description . '</span>';
                }
                break;

            default:
                if ( ! $post ) {
                    $html .= '<label for="' . esc_attr( $field['id'] ) . '">' . "\n";
                }

                if ($description != null && (!array_key_exists('hidden', $field) || !$field['hidden'])) {
                    $html .= '<span class="description">' . $description . '</span>' . "\n";
                }

                if ( ! $post ) {
                    $html .= '</label>' . "\n";
                }
                break;
        }

        // if field is hidden, hide. But, show a description if there is one.
        if (array_key_exists('hidden', $field) && $field['hidden']) {
            $html .= "</div>";

            if ($description !== null) {
                $html .= '<div class="description">' . $description . '</div>' . "\n";
            }
        }

        if ( ! $echo ) {
            return $html;
        }

        echo $html; //phpcs:ignore
        return '';
    }

    /**
     * Generate the python scripts to be uploaded to TouchPoint.
     *
     * @param bool  $toZip Set true to combine into a Zip file.
     * @param array $filenames  Indicate which files should be included, and what they should be called in repoName => newName.
     * Add '*' to the array to include all files regardless of name.  Existing name will be used by default.
     *
     * @return string|array If toZip is true, returns the file path of the zip file.  If toZip is false, returns an array of filename => content.
     * @throws TouchPointWP_Exception
     */
    public function generatePython(bool $toZip, array $filenames = ['*'])
    {
        if ($toZip && !class_exists('\ZipArchive')) {
            throw new TouchPointWP_Exception("ZipArchive extension is not enabled.");
        }

        $out = [];
        $za = null;
        if ($toZip) {
            $out = tempnam(sys_get_temp_dir(), 'TouchPoint-WP-Scripts.zip');
            $za  = new ZipArchive();
            if ($out === false || ! $za->open($out, ZipArchive::CREATE)) {
                throw new TouchPointWP_Exception("Could not create a zip file for the scripts");
            }
        }

        $directory = str_replace('\\', '/', __DIR__ . "/../python/");
        $fnIndex = strlen($directory);

        // Static Python files
        foreach ( glob($directory . '*.py') as $file ) {
            $fn = substr($file, $fnIndex, -3);

            if (!in_array('*', $filenames) && !isset($filenames[$fn])) {
                continue;
            }
            if (isset($filenames[$fn])) {
                $fn = $filenames[$fn];
            }

            if ($toZip) {
                $za->addFile($file, $fn . ".py");
            } else {
                $out[$fn] = file_get_contents($file);
            }
        }

        // Python files generated via PHP
        ob_start();
        // Set variables for scripts
        $host = get_site_url();
        foreach ( glob($directory . '*.php') as $file ) {
            $fn = substr($file, $fnIndex, -4);

            if (!in_array('*', $filenames) && !isset($filenames[$fn])) {
                continue;
            }
            if (isset($filenames[$fn])) {
                $fn = $filenames[$fn];
            }

            include $file; // TODO SOMEDAY This really should be in a sandbox if that were possible.
            $content = ob_get_clean();

            if ($toZip) {
                $za->addFromString($fn . ".py", $content);
            } else {
                $out[$fn] = $content;
            }
        }
        ob_end_clean();

        if ($toZip) {
            // Commit and return file
            $za->close();
            return $out;
        }

        // return either zip location or array with content.
        return $out;
    }

    /**
     * Display an error when there's something wrong with the TouchPoint connection.
     */
    public static function showError($message)
    {
        $class = 'notice notice-error';
        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }

}