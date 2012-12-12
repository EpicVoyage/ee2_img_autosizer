<?php echo form_open('C=addons_extensions'.AMP.'M=save_extension_settings'.AMP.'file=img_autosizer');
foreach ($settings as $k => $v) {
	$this->table->set_template($cp_pad_table_template);
	$this->table->set_heading(
		array('data' => $k, 'style' => 'width:50%;'),
		lang('value')
	);

	foreach ($v as $key => $val) {
		$tag = explode('-', $key);
		$this->table->add_row(lang($tag[0]), $val);
	}

	echo $this->table->generate();
}
echo '<p>'.form_submit('submit', lang('submit'), 'class="submit"').'</p>';
$this->table->clear();
echo form_close();
