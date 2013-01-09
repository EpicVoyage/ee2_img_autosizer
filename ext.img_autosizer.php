<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }
/**
 * =====================================================
 * Image AutoSizer - Put large images on a diet!
 * -----------------------------------------------------
 * Copyright 2012 EpicVoyage. Free for distribution and
 * use. See http://www.epicvoyage.org/ee/ for the latest
 * version.
 * -----------------------------------------------------
 * v1.0: Initial release
 * v1.1: EE 2.5 fixes + Matrix, Assets, Wygwam
 * v1.2: Added support for the upload_preferences Hidden Configuration Variable
 * =====================================================
 */
class Img_autosizer_ext {
	# Basic information about this extension
	var $name = 'Image Autosizer';
	var $version = '1.2';
	var $description = 'Put large images on a diet.';
	var $settings_exist = 'y';
	var $docs_url = 'http://www.epicvoyage.org/ee/';

	# Our script's settings
	var $settings = array(
		'default' => array(
			'name' => false,
			'width' => 800,
			'height' => 600,
			'preserve' => 0
		)
	);

	# PHP5 Constructor
	function __construct($settings = '') {
		$this->EE =& get_instance();

		# Always load the settings here, even when EE does not want us to (ie. when the settings
		# form is displayed).
		if (empty($settings)) {
			$this->EE->db->select('settings');
			$this->EE->db->where('class', __CLASS__);
			$this->EE->db->limit(1);
			$query = $this->EE->db->get('extensions');
		
			if ($query->num_rows() > 0 && $query->row('settings') != '') {
				$this->EE->load->helper('string');
				$settings = strip_slashes(unserialize($query->row('settings')));
			}
		} elseif (!is_array($settings)) {
			$settings = strip_slashes(unserialize($settings));
		}

		# Copy $settings into the global variable.
		if (is_array($settings)) {
			foreach ($settings as $k => $v) {
				$this->settings[$k] = $v;
			}
		}
	}

	# PHP4 Constructor
	function Current_url_ext($settings = '') {
		__construct($settings);
	}

	function _load_upload_prefs() {
		$this->EE->load->model('file_upload_preferences_model');
		$directories = $this->EE->file_upload_preferences_model->get_file_upload_preferences();

		# Grab the site's upload preferences.
		if (is_array($directories)) {
			# Loop through each upload type/place.
			foreach ($directories as $dir) {
				# Use directory-specific settings.
				$this->settings[$dir['id']] = array(
					'name' => $dir['name'],
					'path' => $dir['server_path'],
					'url' => $dir['url'],
					'width' => isset($this->settings[$dir['id']]) && isset($this->settings[$dir['id']]['width']) ?
						$this->settings[$dir['id']]['width'] :
						$this->settings['default']['width'],
					'height' => isset($this->settings[$dir['id']]) && isset($this->settings[$dir['id']]['height']) ?
						$this->settings[$dir['id']]['height'] :
						$this->settings['default']['height'],
					'preserve' => isset($this->settings[$dir['id']]) && isset($this->settings[$dir['id']]['preserve']) ?
						$this->settings[$dir['id']]['preserve'] :
						$this->settings['default']['preserve']
				);
			}
		}

		return;
	}

	# Callback for entry_submission_start hook
	function size_images($channel_id = 0, $autosave = false) {
		# Loop through the POST variables, since that is where we will find image paths.
		$this->_load_upload_prefs();
		$this->check_for_images_array($this->EE->api_channel_entries->data);//$_POST);

		//echo '<pre>'.htmlentities(print_r($_POST, true)).'</pre>'//exit;

		return;
	}

	# Recursive function to locate file paths
	function check_for_images_array(&$a) {
		# If we have submitted a raw file name...
		if (!is_array($a)) {
			# Check whether this is a valid image filename.
			if (preg_match('#\.(?:gif|jpg|jpeg|png|jpe)$#i', $a)) {
				# Replace {filedir_##} with the actual path...
				$search = array();
				$replace = array();
				foreach ($this->settings as $k => $v) {
					if (isset($v['path'])) {
						$search[$k] = '{filedir_'.$k.'}';
						$replace[$k] = $v['path'];
					}
				}
				$file = str_replace($search, $replace, $a);

				if (!file_exists($file)) {
					# Bail out if we could not find the file.
					return;
				}

				# Determine which upload rules to follow...
				$upload_dir = 'default';
				foreach ($search as $k => $v) {
					if (strpos($a, $v) !== false) {
						$upload_dir = $k;
					}
				}

				# If we have reached this point, hurrah, let the diet begin.
				if (($info = $this->resize($file, $upload_dir)) && ($this->EE->db->table_exists('exp_files'))) {
					# Try to update the database table so that the files are listed accurately.
					$this->EE->db->where('file_name', basename($file));
					$this->EE->db->where('upload_location_id', $upload_dir);
					$this->EE->db->update('exp_files', array(
						'file_size' => $info['size'],
						'file_hw_original' => $info['height'].' '.$info['width']
					));
				}
			}

			# Wygwam/raw HTML support.
			foreach ($this->settings as $upload_dir => $v) {
				# No need to check the default settings...
				if ($upload_dir == 'default') {
					continue;
				}

				$path = $v['path'];
				$url = $v['url'];
				$len_url = strlen($url);
				$offset = 0;

				# Loop through instances of our image directories.
				preg_match_all('/<img([^>]*'.str_replace('/', '\x2f', preg_quote($url)).'[^>]*)>/ms', $a, $img_matches, PREG_SET_ORDER);
				foreach ($img_matches as $i => $img_match) {
					preg_match_all('/\s([\w-]+)=([\'"])([^\2]*?)\2/', $img_match[1], $attr_matches, PREG_SET_ORDER);
					$img = array();
					$quoted = '';
					foreach ($attr_matches as $attr_match) {
						$img[$attr_match[1]] = $attr_match[3];
						$quoted = $attr_match[2];
					}

					if (!isset($img['src'])) {
						continue;
					}

					if (strncmp($img['src'], $url, $len_url) == 0) {
						$file = $path.substr($img['src'], $len_url);
						if (file_exists($file)) {
							# If we have reached this point, hurrah, let the diet begin.
							if ($info = $this->resize($file, $upload_dir)) {
								$replace = $img_match[1];

								# Yippee. We get to mod the HTML width/height now...
								if (isset($img['width'])) {
									$replace = str_replace(
										'width='.$quoted.$img['width'].$quoted,
										'width='.$quoted.$info['width'].$quoted,
										$replace
									);
								}
								if (isset($img['height'])) {
									$replace = str_replace(
										'height='.$quoted.$img['height'].$quoted,
										'height='.$quoted.$info['height'].$quoted,
										$replace
									);
								}

								if (isset($img['style'])) {
									$styles = explode(';', $img['style']);
									$style = array();
									foreach ($styles as $v) {
										$e = explode(':', $v);
										if (isset($e[1])) {
											$style[trim($e[0])] = trim($e[1]);
										}
									}

									if (isset($style['width'])) {
										$style['width'] = $info['width'].'px';
									}
									if (isset($style['height'])) {
										$style['height'] = $info['height'].'px';
									}

									$replace_style = '';
									foreach ($style as $k => $v) {
										$replace_style .= $k.':'.$v.';';
									}

									$replace = str_replace(
										'style='.$quoted.$img['style'].$quoted,
										'style='.$quoted.$replace_style.$quoted,
										$replace
									);
								}

								$a = str_replace($img_match[1], $replace, $a);

								if ($this->EE->db->table_exists('exp_files')) {
									# Try to update the database table so that the files are listed accurately.
									$this->EE->db->where('file_name', basename($file));
									$this->EE->db->where('upload_location_id', $upload_dir);
									$this->EE->db->update('exp_files', array(
										'file_size' => $info['size'],
										'file_hw_original' => $info['height'].' '.$info['width']
									));
								}
							}
						}
					}
				}

				# Loop through instances of our image directories (if this is real HTML, it should never return "0").
				/*while ($offset = strpos($a, $url, $offset)) {
					$quote = strpos($a, '"', $offset);

					$file = $path.substr($a, $offset + $len_url, $quote - $offset - $len_url);
					if (file_exists($file)) {
						# If we have reached this point, hurrah, let the diet begin.
						if ($info = $this->resize($file, $upload_dir)) {
							# Yippee. We get to mod the HTML width/height now...
							...

							if ($this->EE->db->table_exists('exp_files')) {
								# Try to update the database table so that the files are listed accurately.
								$this->EE->db->where('file_name', basename($file));
								$this->EE->db->where('upload_location_id', $upload_dir);
								$this->EE->db->update('exp_files', array(
									'file_size' => $info['size'],
									'file_hw_original' => $info['height'].' '.$info['width']
								));
							}
						}
					}

					$offset++;
				}*/
			}

		# Matrix/Assets file?
		} elseif (isset($a['filedir']) && isset($a['filename']) && !empty($a['filedir']) && !empty($a['filename'])) {
			# Make the code more readable.
			$dir = $a['filedir'];
			$file = $this->settings[$dir]['path'].'/'.$a['filename'];

			# Verify that the file is an image and exists.
			if (!preg_match('#\.(?:gif|jpg|jpeg|png|jpe)$#i', $file) || !file_exists($file)) {
				return;
			}

			# Let's resize...
			$this->resize($file, $dir);

		# If we are any other type of array, call ourself with their values also.
		} else {
			foreach ($a as $k => &$v) {
				$alt = '';

				# Support Older EE Versions.
				if (is_string($v) && isset($a[$k.'_directory']) && isset($this->settings[$a[$k.'_directory']])) {
					# EE 2.1.3, &c.
					$alt = '{filedir_'.$a[$k.'_directory'].'}';
					if (strpos($v, $alt) !== false) {
						# EE 2.5, &c.
						$alt = '';
					}

					$v = $alt.$v;
				}

				# And recurse...
				$this->check_for_images_array($v);
			}
		}

		return;
	}

	# Resize an image on-disk.
	function resize($file, $upload_dir_id) {
		$size = getimagesize($file);
		$need_w = ($size[0] > $this->settings[$upload_dir_id]['width']);
		$need_h = ($size[1] > $this->settings[$upload_dir_id]['height']);

		if ($need_w || $need_h) {
			# Move the file temporarily.
			rename($file, $file.'.orig');

			# Configuration for the CodeIgniter image library
			$config['width'] = $this->settings[$upload_dir_id]['width'];
			$config['height'] = $this->settings[$upload_dir_id]['height'];
			$config['maintain_ratio'] = true;
			$config['master_dim'] = 'auto';
			$config['library_path'] = $this->EE->config->item('image_library_path');
			$config['image_library'] = $this->EE->config->item('image_resize_protocol');

			$config['source_image'] = file_exists($file.'.orig') ? $file.'.orig' : $file;
			$config['new_image'] = $file;

			# Load the image library...
			if (!isset($this->EE->image_lib)) {
				$this->EE->load->library('image_lib', $config);
			} else {
				$this->EE->image_lib->initialize($config);
			}

			# And... resize.
			$this->EE->image_lib->resize();

			# If there was an error, keep the original file.
			if (!file_exists($file)) {
				rename($file.'.orig', $file);
			# Preserve disk space if requested.
			} elseif (!$this->settings[$upload_dir_id]['preserve']) {
				unlink($file.'.orig');
			}

			$info = getimagesize($file);

			//echo $this->settings[$upload_dir_id]['width'].'x'.$this->settings[$upload_dir_id]['height'];
			//echo ' => '.$info[0].'x'.$info[1].'<br />';

			return $info ? array(
				'width' => $info[0],
				'height' => $info[1],
				'size' => filesize($file)
			) : false;
		}

		return false;
	}

	# Generate a form for the user to enter settings by.
	function settings_form($current) {
		$this->EE->load->helper('form');
		$this->EE->load->library('table');

		$this->_load_upload_prefs();

		# Defines
		$vars = array();
		$yes_no_options = array('1' => lang('yes'), '0' => lang('no'));

		# Loop through all the defined upload directories.
		$vars['settings'][lang('Default Settings')] = array(
			'width' => form_input('width', $this->settings['default']['width']),
			'height' => form_input('height', $this->settings['default']['height']),
			'preserve' => form_dropdown('preserve', $yes_no_options, $this->settings['default']['preserve'])
		);
		foreach ($this->settings as $k => $v) {
			if (!is_numeric($k)) {
				continue;
			}

			$title = lang('settings_for').($v['name'] ? ' '.htmlentities($v['name']) : 'Unnamed').' ('.htmlentities($v['path']).')';

			$vars['settings'][$title]['width-'.$k] = form_input('width-'.$k, $v['width']);
			$vars['settings'][$title]['height-'.$k] = form_input('height-'.$k, $v['height']);
			$vars['settings'][$title]['preserve-'.$k] = form_dropdown('preserve-'.$k, $yes_no_options, $v['preserve']);
		}

		# Return an array of HTML strings.
		return $this->EE->load->view('index', $vars, true);
	}

	# Save the settings submitted from the settings() form.
	function save_settings() {
		if (empty($_POST)) {
			show_error($this->EE->lang->line('unauthorized_access'));
		}
	
		unset($_POST['submit']);
		$this->EE->lang->loadfile('img_autosizer');
		$this->_load_upload_prefs();

		# Read answers...
		foreach ($_POST as $k => $v) {
			$tag = explode('-', $k);
			$loc = (count($tag) == 2) ? $tag[1] : 'default';
			$this->settings[$loc][$tag[0]] = intval($this->EE->input->post($k));
		}

		# Validate our settings array.
		foreach ($this->settings as &$v) {
			if ($v['width'] <= 0) {
				$v['width'] = $this->settings['default']['width'];
			}
			if ($v['height'] <= 0) {
				$v['height'] = $this->settings['default']['height'];
			}
			$v['preserve'] = ($v['preserve'] == 0) ? 0 : 1;
		}

		# And store...	
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->update('extensions', array('settings' => serialize($this->settings)));
		$this->EE->session->set_flashdata('message_success', $this->EE->lang->line('preferences_updated'));

		return;
	}
	
	# Install ourselves into the database.
	function activate_extension() {
		$ext_template = array(
			'class'	=> __CLASS__,
			'settings' => serialize($this->settings),
			'priority' => 5,
			'version'  => $this->version,
			'enabled'  => 'y',
			'hook'     => 'entry_submission_start',
			'method'   => 'size_images'
		);

		$this->disable_extension();
		$this->EE->db->insert('extensions', $ext_template);

		return;
	}


	# No updates yet, but the manual says this function is required.
	function update_extension($current = '') {
		return;
	}

	# Uninstalls extension
	function disable_extension() {
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->delete('extensions');

		return;
	}
}

/* End of file ext.img_autosizer.php */
/* Location: ./system/expressionengine/third_party/img_autosizer/ext.img_autosizer.php */
