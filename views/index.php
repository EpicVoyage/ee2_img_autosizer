<?=form_open('C=addons_extensions'.AMP.'M=save_extension_settings'.AMP.'file=img_autosizer');?>
<?php
$this->table->set_template($cp_pad_table_template);
$this->table->set_heading(
    array('data' => lang('setting'), 'style' => 'width:50%;'),
    lang('value')
);

foreach ($settings as $key => $val) {
	$tag = explode('-', $key);
	$this->table->add_row(lang($tag[0]), $val);
}

echo $this->table->generate();
?>
<p><?=form_submit('submit', lang('submit'), 'class="submit"')?></p>
<?php $this->table->clear()?>
<?=form_close()?>
