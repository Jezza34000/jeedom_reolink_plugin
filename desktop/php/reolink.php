<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('reolink');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$nvrs = array();
$cameras = array();
foreach ($eqLogics as $eqLogic) {
	if (method_exists($eqLogic, 'isNvrParent') && $eqLogic->isNvrParent()) {
		$nvrs[] = $eqLogic;
	} else {
		$cameras[] = $eqLogic;
	}
}
$renderCard = function ($eqLogic) {
	$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
	$model = strval($eqLogic->getConfiguration('model', ''));
	$ip = strval($eqLogic->getConfiguration('adresseip', ''));
	$channel = $eqLogic->getConfiguration('defined_channel', '');
	$info = $ip;
	if ($channel !== '' && $channel !== null) {
		$info = trim($ip . ' · Canal ' . $channel);
	}
	echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
	echo '<img src="' . $eqLogic->getImage() . '"/>';
	echo '<br>';
	echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
	echo '<span class="hiddenAsCard displayTableRight hidden">';
	if ($model !== '') {
		echo '<span class="label label-default">' . htmlspecialchars($model, ENT_QUOTES, 'UTF-8') . '</span> ';
	}
	if ($info !== '') {
		echo '<span class="label label-info">' . htmlspecialchars($info, ENT_QUOTES, 'UTF-8') . '</span> ';
	}
	echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Équipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Équipement non visible}}"></i>';
	echo '</span>';
	echo '</div>';
};
?>

<div class="row row-overflow">
	<!-- Page d'accueil du plugin -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i>  {{Gestion}}</legend>
		<!-- Boutons de gestion du plugin -->
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
			<div class="cursor logoSecondary" id="btSyncCameraPluginAll">
				<i class="fas fa-video"></i>
				<br>
				<span>{{Sync. plugin Caméra}}</span>
			</div>
		</div>
		<?php
		if (count($eqLogics) == 0) {
			echo '<br/><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucune caméra Reolink n\'est paramétrée, cliquez sur "Ajouter" pour commencer}}</div>';
		} else {
			echo '<div class="input-group" style="margin:5px;">';
			echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic"/>';
			echo '<div class="input-group-btn">';
			echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
			echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
			echo '</div>';
			echo '</div>';

			echo '<legend><i class="fas fa-video"></i> {{Mes caméras}}</legend>';
			echo '<div class="eqLogicThumbnailContainer">';
			foreach ($cameras as $eqLogic) {
				$renderCard($eqLogic);
			}
			echo '</div>';

			if (!empty($nvrs)) {
				echo '<legend><i class="fas fa-hdd"></i> {{Mes NVR}}</legend>';
				echo '<div class="eqLogicThumbnailContainer">';
				foreach ($nvrs as $eqLogic) {
					$renderCard($eqLogic);
				}
				echo '</div>';
			}
		}
		?>
	</div> <!-- /.eqLogicThumbnailDisplay -->

	<!-- Page de présentation de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<!-- barre de gestion de l'équipement -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs">  {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<!-- Onglet de configuration de l'équipement -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;"/>
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label" >{{Objet parent}}</label>
								<div class="col-sm-7">
									<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-7">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Options}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
								</div>
							</div>
							<legend id="lgd_accessParams"><i class="fas fa-cogs"></i> <span id="lbl_accessParams">{{Paramètres d'accès à la caméra}}</span></legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{IP / Nom d'hôte}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse IP ou le nom d'hôte de la caméra}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="adresseip"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port HTTP/HTTPS}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le port de l'interface web de la caméra uniquement si vous l'avez modifié. Sinon, laissez ce champ vide}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="{{(facultatif)}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port ONVIF}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le port du protocole ONVIF uniquement si vous l'avez modifié. Sinon, laissez ce champ vide}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port_onvif" placeholder="{{8000}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Canal NVR}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Numéro de canal si la caméra est derrière un NVR (0 = premier canal). Laissez vide pour un NVR parent ou une caméra autonome.}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="defined_channel" placeholder="{{Vide = NVR ou autonome, 0..N = canal}}"/>
									<div id="div_nvrParentHint" class="alert alert-info" style="display:none;margin-top:8px;">
										{{Équipement NVR parent (pas de canal) : commandes système uniquement (firmware, NTP, disque, UPnP, buzzer NVR…). Les réglages caméra sont sur les équipements enfants (canal renseigné).}}
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Login}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'identifiant utilisé pour accéder à l'interface web de la caméra}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="login"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{Mot de passe}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le mot de passe}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control inputPassword" data-l1key="configuration" data-l2key="password"/>
								</div>
							</div>
							<div class="form-group">
									<label class="col-sm-3 control-label">{{Type de connexion}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Type de connexion pour se connecter à la caméra, attention HTTPS doit être activé sur la caméra pour fonctionner}}"></i></sup></label>
									<div class="col-sm-3">
											<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cnxtype">
													<option value="http">{{HTTP}}</option>
													<option value="https">{{HTTPS}}</option>
											</select>
									</div>
							</div>
							<div class="form-group expertModeVisible">
								<label class="col-sm-3 control-label">{{Auto-actualisation (cron)}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Actualise les paramètres globaux de la caméra. N'influence pas la remontée de détection de mouvement}}"></i></sup>
								</label>
									<div class="col-sm-3">
										<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="autorefresh" placeholder="*/15 * * * *"/>
									</div>
									<div class="col-sm-1">
										<i class="fas fa-question-circle cursor floatright" id="bt_cronGenerator" title="{{Assistant cron}}"></i>
									</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{}}</label>
								<div class="col-sm-3">
									<a class="btn btn-default" id="btCheckConnexion"><i class="fas fa-sync-alt"></i> {{Tester la connexion}}</a>
								</div>
							</div>
						</div>

						<!-- Partie droite de l'onglet "Équipement" -->
						<div class="col-lg-6">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<div class="text-center">
									<img name="icon_visu" id="img_device" src="<?php echo $plugin->getPathImgIcon(); ?>" style="max-height:140px;max-width:160px;"/>
								</div>
							</div>

							<div class="form-group">
								<div class="col-sm-12">
									<div class="row">
										<div class="col-sm-6 cameraOnlyAction" style="margin-bottom:8px;">
											<a class="btn btn-block btn-success" id="btOpenLive"><i class="fas fa-play-circle"></i> {{Live Low/High}}</a>
										</div>
										<div class="col-sm-6 cameraOnlyAction" style="margin-bottom:8px;">
											<a class="btn btn-block btn-warning eqLogicAction" id="btManagePatrol"><i class="fas fa-route"></i> {{Gérer les rondes PTZ}}</a>
										</div>
										<div class="col-sm-6" style="margin-bottom:8px;">
											<a class="btn btn-block btn-primary eqLogicAction" id="btGetCamNFO"><i class="fas fa-download"></i> {{Récupérer les informations}}</a>
										</div>
										<div class="col-sm-6" style="margin-bottom:8px;">
											<a class="btn btn-block btn-default eqLogicAction" id="btGetCMD"><i class="fas fa-plus-circle"></i> {{Créer (recréer) les commandes}}</a>
										</div>
										<div class="col-sm-12 cameraOnlyAction" style="margin-bottom:8px;">
											<a class="btn btn-block btn-info" id="btSyncCameraPlugin"><i class="fas fa-video"></i> {{Synchroniser vers plugin Caméra}}</a>
										</div>
									</div>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label" id="lbl_deviceName">{{Nom de la caméra}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="name"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label" id="lbl_deviceModel">{{Modèle}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="model"></span>
								</div>
							</div>
							<div class="form-group" id="fg_hostModel">
								<label class="col-sm-3 control-label">{{Modèle NVR (hôte)}}</label>
								<div class="col-sm-7">
									<span id="span_hostModelDisplay"></span>
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="hostModel" style="display:none;"></span>
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="hostName" style="display:none;"></span>
								</div>
							</div>
							<div class="form-group" id="fg_hostChannelNum">
								<label class="col-sm-3 control-label">{{Canaux NVR}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="hostChannelNum"></span>
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="channelNum" style="display:none;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Connectivité}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="linkconnection"></span>
								</div>
							</div>
							<div class="form-group" id="fg_supportAi">
								<label class="col-sm-3 control-label">{{Caméra IA}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="supportai"></span>
								</div>
							</div>

							<div class="panel-group" id="accordionReolinkTech">
								<div class="panel panel-default">
									<div class="panel-heading">
										<h3 class="panel-title">
											<a class="accordion-toggle collapsed" data-toggle="collapse" data-parent="#accordionReolinkTech" aria-expanded="false" href="#panel_techDetails"><i class="fas fa-network-wired"></i> {{Détails techniques & Ports réseau}}</a>
										</h3>
									</div>
									<div id="panel_techDetails" class="panel-collapse collapse" aria-expanded="false">
										<div class="panel-body">
											<div class="form-group">
												<label class="col-sm-4 control-label">{{UID}}</label>
												<div class="col-sm-8">
													<span class="eqLogicAttr" data-l1key="configuration" data-l2key="uid"></span>
												</div>
											</div>
											<div class="form-group">
												<label class="col-sm-4 control-label">{{Build No}}</label>
												<div class="col-sm-8">
													<span class="eqLogicAttr" data-l1key="configuration" data-l2key="buildDay"></span>
												</div>
											</div>
											<div class="form-group">
												<label class="col-sm-4 control-label">{{Version hardware}}</label>
												<div class="col-sm-8">
													<span class="eqLogicAttr" data-l1key="configuration" data-l2key="hardVer"></span>
												</div>
											</div>
											<div class="form-group">
												<label class="col-sm-4 control-label">{{Version firmware}}</label>
												<div class="col-sm-8">
													<span class="eqLogicAttr" data-l1key="configuration" data-l2key="firmVer"></span>
												</div>
											</div>
											<div class="form-group">
												<label class="col-sm-4 control-label">{{Version config}}</label>
												<div class="col-sm-8">
													<span class="eqLogicAttr" data-l1key="configuration" data-l2key="cfgVer"></span>
												</div>
											</div>
											<div class="form-group">
												<label class="col-sm-4 control-label">{{Détail}}</label>
												<div class="col-sm-8">
													<span class="eqLogicAttr" data-l1key="configuration" data-l2key="detail"></span>
												</div>
											</div>
											<table class="table table-bordered table-condensed" style="margin-top:10px;">
												<thead>
													<tr>
														<th>{{Port}}</th>
														<th>{{Valeur}}</th>
														<th>{{État}}</th>
													</tr>
												</thead>
												<tbody>
													<tr>
														<td>{{HTTP}}</td>
														<td><span class="eqLogicAttr label label-info" style="font-size:1em;cursor:default;" data-l1key="configuration" data-l2key="httpPort"></span></td>
														<td><span class="eqLogicAttr hidden" data-l1key="configuration" data-l2key="httpEnable"></span><span class="reolinkPortBadge" data-enable-key="httpEnable"></span></td>
													</tr>
													<tr>
														<td>{{HTTPS}}</td>
														<td><span class="eqLogicAttr label label-info" style="font-size:1em;cursor:default;" data-l1key="configuration" data-l2key="httpsPort"></span></td>
														<td><span class="eqLogicAttr hidden" data-l1key="configuration" data-l2key="httpsEnable"></span><span class="reolinkPortBadge" data-enable-key="httpsEnable"></span></td>
													</tr>
													<tr>
														<td>{{Media}}</td>
														<td><span class="eqLogicAttr label label-info" style="font-size:1em;cursor:default;" data-l1key="configuration" data-l2key="mediaPort"></span></td>
														<td><span class="eqLogicAttr hidden" data-l1key="configuration" data-l2key="mediaEnable"></span><span class="reolinkPortBadge" data-enable-key="mediaEnable"></span></td>
													</tr>
													<tr>
														<td>{{ONVIF}}</td>
														<td><span class="eqLogicAttr label label-info" style="font-size:1em;cursor:default;" data-l1key="configuration" data-l2key="onvifPort"></span></td>
														<td><span class="eqLogicAttr hidden" data-l1key="configuration" data-l2key="onvifEnable"></span><span class="reolinkPortBadge" data-enable-key="onvifEnable"></span></td>
													</tr>
													<tr>
														<td>{{RTMP}}</td>
														<td><span class="eqLogicAttr label label-info" style="font-size:1em;cursor:default;" data-l1key="configuration" data-l2key="rtmpPort"></span></td>
														<td><span class="eqLogicAttr hidden" data-l1key="configuration" data-l2key="rtmpEnable"></span><span class="reolinkPortBadge" data-enable-key="rtmpEnable"></span></td>
													</tr>
													<tr>
														<td>{{RTSP}}</td>
														<td><span class="eqLogicAttr label label-info" style="font-size:1em;cursor:default;" data-l1key="configuration" data-l2key="rtspPort"></span></td>
														<td><span class="eqLogicAttr hidden" data-l1key="configuration" data-l2key="rtspEnable"></span><span class="reolinkPortBadge" data-enable-key="rtspEnable"></span></td>
													</tr>
												</tbody>
											</table>
										</div>
									</div>
								</div>
							</div>
						</div>
					</fieldset>
				</form>
				<hr>
			</div><!-- /.tabpanel #eqlogictab-->

			<!-- Onglet des commandes de l'équipement -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br/><br/>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th class="hidden-xs" style="min-width:50px;width:70px;">{{Id}}</th>
								<th style="min-width:200px;width:350px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th style="min-width:100px;width:150px;">{{Logical ID}}</th>
								<th style="min-width:150px;width:250px;">{{Paramètres}}</th>
								<th style="min-width:150px;width:250px;">{{Options}}</th>
								<th style="min-width:80px;width:120px;">{{État}}</th>
								<th style="min-width:80px;width:140px;">{{Action}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab-->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<?php
$reolinkCmdHelp = array();
$reolinkCmdConf = dirname(__FILE__) . '/../../core/config/reolinkapicmd.json';
if (is_readable($reolinkCmdConf)) {
	$raw = file_get_contents($reolinkCmdConf);
	$decoded = json_decode($raw, true);
	if (is_array($decoded) && isset($decoded['commands']) && is_array($decoded['commands'])) {
		$i18nFile = 'plugins/reolink/core/class/reolink.class.php';
		foreach ($decoded['commands'] as $command) {
			$lid = strval($command['logicalId'] ?? '');
			if ($lid === '') {
				continue;
			}
			$cfg = (isset($command['configuration']) && is_array($command['configuration'])) ? $command['configuration'] : array();
			$help = strval($cfg['help'] ?? '');
			$valuesHelp = strval($cfg['valuesHelp'] ?? '');
			$reolinkCmdHelp[$lid] = array(
				'help' => ($help !== '') ? __($help, $i18nFile) : '',
				'valuesHelp' => ($valuesHelp !== '') ? __($valuesHelp, $i18nFile) : '',
			);
		}
	}
}
?>
<script>
window.REOLINK_CMD_HELP = <?php echo json_encode($reolinkCmdHelp, JSON_UNESCAPED_UNICODE); ?>;
</script>
<?php include_file('desktop', 'reolink', 'js', 'reolink');?>
<?php include_file('core', 'plugin.template', 'js');?>
