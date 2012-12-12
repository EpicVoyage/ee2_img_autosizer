<?php
/**
 * =====================================================
 * When an image is encountered that is too large, put
 * it on a diet.
 * -----------------------------------------------------
 * Copyright 2011 Key Creative. Free for distribution
 * and use. Visit http://www.keycreative.com/ee.
 * -----------------------------------------------------
 * v1.0: Initial release
 * =====================================================
 */

if (!defined('BASEPATH')) {
	exit('No direct script access allowed');
}

class Img_autosizer_ext {
	# Basic information about this extension
	var $name = 'Image Autosizer';
	var $version = '1.0';
	var $description = 'When an image is encountered that is too large, put it on a diet.';
	var $settings_exist = 'y';
	var $docs_url = 'http://www.keycreative.com/ee';

	# Our script's settings
	var $settings = array(
		'default' => array(
			'width' => 800,
			'height' => 600,
			'preserve' => 0
		)
	);

	# PHP5 Constructor
	function __construct($settings = '') {
		$this->EE =& get_instance();

		# Bug? According to the docs (and our activation function), $setting
		# should not be empty after the first initialization.
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

		# Grab the site's upload preferences.
		$this->EE->db->select('id, server_path');
		$query = $this->EE->db->get('exp_upload_prefs');
		if ($query->num_rows) {
			# Loop through each upload type/place.
			foreach ($query->result_array() as $row) {
				# Use directory-specific settings.
				$params = array(
					'path' => $row['server_path'],
					'width' => isset($settings[$row['id']]) && isset($settings[$row['id']]['width']) ?
						$settings[$row['id']]['width'] :
						$this->settings['default']['width'],
					'height' => isset($settings[$row['id']]) && isset($settings[$row['id']]['height']) ?
						$settings[$row['id']]['height'] :
						$this->settings['default']['height'],
					'preserve' => isset($settings[$row['id']]) && isset($settings[$row['id']]['preserve']) ?
						$settings[$row['id']]['preserve'] :
						$this->settings['default']['preserve']
				);

				$this->settings[$row['id']] = $params;
			}
		}

		return;
	}

	# PHP4 Constructor
	function Current_url_ext($settings = '') {
		__construct($settings);

		return;
	}

	# Callback for entry_submission_start hook
	function size_images($channel_id = 0, $autosave = false) {
		# Loop through the POST variables, since that is where we will find image paths.
		foreach ($_POST as $k => $v) {
			if (is_array($v)) {
				$this->check_for_images_array($_POST);
			}
		}

		return;
	}

	# Recursive function to locate file paths
	function check_for_images_array(&$a) {
		# If we have submitted a raw file name...
		if (!is_array($a)) {
			$file = $a;
			if (preg_match('#^/.*\.(?:gif|jpg|jpeg|png|jpe)$#i', $file)) {
				if (!file_exists($file)) {
					$file = $_SERVER['DOCUMENT_ROOT'].$file;
					if (!$file_exists($file)) {
						# Bail out if we could not find the file.
						return;
					}
				}

				# TODO: Test this block?
				$upload_dir = 'default';
				/*$dir = realpath(dirname($file));
				foreach ($this->settings as $id => $s) {
					if (realpath($s['path']) == $dir) {
						$upload_dir = $id;
					}
				}*/

				# If we reach this point, we have found the file.
				$this->resize($file, $upload_dir);
			}
		# Matrix file?
		} elseif (isset($a['filedir']) && isset($a['filename']) && !empty($a['filedir']) && !empty($a['filename'])) {
			# Make the code more readable.
			$dir = $a['filedir'];
			$file = $this->settings[$dir]['path'].'/'.$a['filename'];

			# Verify that the file is an image and exists.
			if (!preg_match('/.(?:gif|jpg|jpeg|png|jpe)/i', $file) || !file_exists($file)) {
				return;
			}

			# Check whether we need to resize the image.
			$size = getimagesize($file);
			if (($size[0] > $this->settings[$dir]['width']) || ($size[1] > $this->settings[$dir]['height'])) {
				# Let's resize...
				$this->resize($file, $dir);
			}
		# If we are any other type of array, call ourself with their values also.
		} else {
			foreach ($a as $k => &$v) {
				$this->check_for_images_array($v);
			}
		}

		return;
	}

	# Resize an image on-disk.
	function resize($file, $id) {
		# Move the file temporarily.
		rename($file, $file.'.orig');

		# Configuration for the CodeIgniter image library
		$config['width'] = $this->settings[$id]['width'];
		$config['height'] = $this->settings[$id]['height'];
		$config['maintain_ratio'] = true;
		$config['master_dim'] = 'auto';
		$config['library_path'] = $this->EE->config->item('image_library_path');
		$config['image_library'] = $this->EE->config->item('image_resize_protocol');

		$config['source_image'] = $file.'.orig';
		$config['new_image'] = $file;

		# Load the image library and resize the image.
		$this->EE->load->library('image_lib', $config);
		$this->EE->image_lib->resize();

		# If there was an error, keep the original file.
		if (!file_exists($file)) {
			rename($file.'.orig', $file);
		# Preserve disk space if requested.
		} elseif (!$this->settings[$id]['preserve']) {
			unlink($file.'.orig');
		}

		return;
	}

	# Generate a form for the user to enter settings by.
	function settings_form($current) {
		$this->EE->load->helper('form');
		$this->EE->load->library('table');

		# Defines
		$vars = array();
		$yes_no_options = array('1' => lang('yes'), '0' => lang('no'));

		# Loop through all the defined upload directories.
		$vars['settings'] = array(
			'default' => '',
			'width' => form_input('width', $this->settings['default']['width']),
			'height' => form_input('height', $this->settings['default']['height']),
			'preserve' => form_dropdown('preserve', $yes_no_options, $this->settings['default']['preserve'])
		);
		foreach ($this->settings as $k => $v) {
			if (!is_numeric($k)) {
				continue;
			}

			$vars['settings']['-'.$k] = '&nbsp;';
			$vars['settings']['label-'.$k] = htmlentities($v['path']);
			$vars['settings']['width-'.$k] = form_input('width-'.$k, $v['width']);
			$vars['settings']['height-'.$k] = form_input('height-'.$k, $v['height']);
			$vars['settings']['preserve-'.$k] = form_dropdown('preserve-'.$k, $yes_no_options, $v['preserve']);
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
