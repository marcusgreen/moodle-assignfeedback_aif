<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * German language strings for assignfeedback_aif.
 *
 * @package     assignfeedback_aif
 * @category    string
 * @copyright   2024 Marcus Green
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aicontrolinactive_teacher'] = 'Die automatische KI-Feedback-Generierung ist für diese Aufgabe aktiviert, aber die KI ist derzeit im KI-Kontrollzentrum nicht freigeschaltet. Lernende erhalten kein KI-Feedback, bis Sie die KI für diesen Kurs aktivieren.';
$string['aif:viewstatus'] = 'Status der KI-Feedback-Generierung anzeigen';
$string['ainavailable'] = 'KI-Backend ist für diesen Zweck nicht verfügbar';
$string['analysisnosubmission'] = 'Keine Abgabeinhalte für diese/n Lernende/n gefunden.';
$string['analysisonlinetext'] = 'Online-Textabgabe wird einbezogen';
$string['analysisprocessablefiles'] = 'Dateien, die analysiert werden:';
$string['analysisskippedfiles'] = 'Dateien, die nicht analysiert werden können (werden ausgeschlossen):';
$string['autogenerate'] = 'Feedback bei Abgabe automatisch generieren';
$string['autogenerate_help'] = 'Wenn aktiviert, wird KI-Feedback automatisch generiert, wenn Lernende ihre Aufgabe abgeben. Die Feedback-Generierung läuft als Hintergrundaufgabe und kann einige Minuten dauern.<br><br><strong>Übungsmodus:</strong> Wenn der Bewertungsworkflow <strong>deaktiviert</strong> ist, sehen Lernende das KI-Feedback sofort nach der Generierung ohne Überprüfung durch die Lehrkraft. Ein separater Hinweis zeigt an, dass das Feedback nicht überprüft wurde.<br><br><strong>Lehrkraft-geprüfter Modus:</strong> Wenn der Bewertungsworkflow <strong>aktiviert</strong> ist, sehen Lernende das KI-Feedback erst, nachdem die Lehrkraft die Bewertung freigegeben hat. So können Lehrkräfte das Feedback vor der Anzeige überprüfen und bearbeiten.';
$string['backends'] = 'KI-Backend-System';
$string['backends_text'] = 'Das Core-KI-System wurde mit Moodle 4.5 eingeführt. Local AI manager bietet erweiterte Funktionen wie Nutzungskontingente, rollenbasierte Konfiguration und zweckspezifische KI-Werkzeuge.';
$string['batchdeletefeedbackcomplete'] = 'KI-Feedback wurde für die ausgewählten Lernenden gelöscht.';
$string['batchoperationconfirmdeletefeedbackai'] = 'KI-Feedback für alle ausgewählten Nutzer/innen löschen?';
$string['batchoperationconfirmgeneratefeedbackai'] = 'KI-Feedback für alle ausgewählten Nutzer/innen generieren?';
$string['batchoperationdeletefeedbackai'] = 'KI-Feedback löschen';
$string['batchoperationgeneratefeedbackai'] = 'KI-Feedback generieren';
$string['cachecleanupdelay'] = 'Cache-Bereinigungsverzögerung (Tage)';
$string['cachecleanupdelay_text'] = 'Extrahierte Dateiinhalte werden zwischengespeichert, um wiederholte KI-Aufrufe zu vermeiden. Cache-Einträge, auf die innerhalb dieser Anzahl von Tagen nicht zugegriffen wurde, werden bereinigt. Auf 0 setzen, um die Bereinigung zu deaktivieren.';
$string['confirmgeneratefeedback'] = 'Hierbei werden die Abgabedaten der/des Lernenden (Text und hochgeladene Dateien) zur Analyse an ein KI-System gesendet. Dateien, die nicht konvertiert werden können, werden ausgeschlossen. Vorhandenes KI-Feedback wird ersetzt. Fortfahren?';
$string['coreaisubsystem'] = 'Core-KI-Subsystem';
$string['default_help'] = 'Das Plugin wird standardmäßig aktiviert, wenn eine neue Aufgabe erstellt wird';
$string['defaultdisclaimer'] = '(Dieses Feedback wurde von einem KI-System generiert und von Ihrer Lehrkraft überprüft.)';
$string['defaultpracticedisclaimer'] = '(Dieses Feedback wurde von einem KI-System generiert.)';
$string['deletefeedbackai'] = 'KI-Feedback löschen';
$string['disclaimer'] = 'Haftungsausschluss';
$string['disclaimer_text'] = 'Text, der an jede KI-Antwort angehängt wird und darauf hinweist, dass das Feedback von einem KI-System und nicht von einer Person stammt.';
$string['enabled'] = 'Aktiviert';
$string['enabled_help'] = 'KI-Feedback-Plugin aktivieren. <br>Hinweis: Bilder und PDFs werden automatisch per KI-Bild-zu-Text (ITT) in Text umgewandelt. Für DOCX und andere Dokumentformate wird ein Dokumentkonverter (z.B. Google Drive) als Fallback verwendet.';
$string['enabledbydefault'] = 'Standardmäßig aktiviert';
$string['enableexpertmode'] = 'Expertenmodus aktivieren';
$string['enableexpertmode_text'] = 'Wenn aktiviert, wird in den Aufgabeneinstellungen eine Schaltfläche „Expertenmodus-Vorlage" angezeigt, mit der Lehrkräfte die vollständige Prompt-Vorlage mit allen Platzhaltern direkt verwenden können.';
$string['err_retrievingfeedback'] = 'Fehler beim Abrufen des Feedbacks vom KI-Werkzeug: {$a}';
$string['err_retrievingfeedback_checkconfig'] = 'Feedback konnte nicht abgerufen werden. Die Konfiguration des KI-Systems ist möglicherweise fehlerhaft. Bitte wenden Sie sich an Ihre Administration.';
$string['erroremptysubmission'] = 'Keine analysierbaren Abgabeinhalte gefunden. Die Abgabe war entweder leer oder alle eingereichten Dateien konnten nicht konvertiert werden.';
$string['errornosubmission'] = 'Keine abgegebene Aufgabe für diese/n Lernende/n gefunden.';
$string['errorskippedfilesdetail'] = 'Übersprungene Dateien: {$a}';
$string['expertmodeconfirm'] = 'Dies ersetzt den aktuellen Prompt durch die Expertenmodus-Vorlage.<br><br><strong>Was ist der Expertenmodus?</strong><br>Im Expertenmodus haben Sie die volle Kontrolle über den gesamten KI-Prompt. Die Admin-Vorlage wird ignoriert und Ihr Prompt wird direkt an die KI gesendet.<br><br><strong>Verfügbare Platzhalter:</strong><ul><li><code>{{submission}}</code> - Der Abgabetext der/des Lernenden (erforderlich, um den Expertenmodus zu aktivieren)</li><li><code>{{rubric_section}}</code> - Der Abschnitt mit den Bewertungskriterien inkl. Überschrift (nur wenn Rubriken konfiguriert sind)</li><li><code>{{rubric}}</code> - Der reine Rubrik-Kriterientext (ohne Überschrift)</li><li><code>{{assignmentname}}</code> - Der Name der Aufgabe</li><li><code>{{description_section}}</code> - Der Abschnitt der Aufgabenbeschreibung mit Überschrift (leer, wenn keine Beschreibung)</li><li><code>{{description}}</code> - Der reine Aufgabenbeschreibungstext</li><li><code>{{instructions_section}}</code> - Der Abschnitt der Aktivitätsanweisungen mit Überschrift (leer, wenn keine Anweisungen)</li><li><code>{{activityinstructions}}</code> - Der reine Aktivitätsanweisungstext</li><li><code>{{language}}</code> - Die Sprache der/des Nutzenden für die Antwort</li></ul><strong>Hinweis:</strong> Das Vorhandensein von <code>{{submission}}</code> in Ihrem Prompt aktiviert den Expertenmodus. Ohne diesen Platzhalter wird Ihr Prompt in die Admin-Vorlage am Platzhalter <code>{{prompt}}</code> eingefügt.<br><br><strong>Expertenmodus verlassen:</strong> Um zum normalen Modus zurückzukehren, entfernen Sie einfach alle Platzhalter (insbesondere <code>{{submission}}</code>) aus dem Prompt-Feld. Ihr Prompt wird dann wie gewohnt in die Admin-Vorlage eingefügt.<br><br>Fortfahren?';
$string['expertmodepromptplaceholder'] = 'Bitte geben Sie hier Ihren spezifischen Prompt oder Ihre Anweisungen ein';
$string['feedbackgenerating'] = 'KI-Feedback wird im Hintergrund generiert. Diese Seite wird automatisch aktualisiert, sobald es fertig ist.';
$string['feedbackgeneratingprogress'] = 'KI-Feedback wird generiert ({$a->current}/{$a->total})...';
$string['feedbackgenerationcomplete'] = 'KI-Feedback wurde erfolgreich generiert.';
$string['feedbackgenerationerror'] = 'KI-Feedback-Generierung fehlgeschlagen: {$a}';
$string['feedbackskippedfiles'] = 'Hinweis: Die folgenden Dateien konnten nicht von der KI analysiert werden und wurden nicht in das Feedback einbezogen: {$a}';
$string['file'] = 'Prompt-Datei';
$string['file_help'] = 'Laden Sie eine Textdatei mit dem Prompt für die KI-Feedback-Generierung hoch. Der Dateiinhalt wird anstelle des obigen Textfeldes als Prompt verwendet.';
$string['generatefeedbackai'] = 'KI-Feedback generieren';
$string['introattachmentsheading'] = '[Zusätzliche Referenzdateien der Lehrkraft]';
$string['localaimanager'] = 'Local AI manager';
$string['pluginname'] = 'KI-gestütztes Feedback';
$string['pluginname_userfaced'] = 'Feedbacktyp „KI-gestütztes Feedback" in der Aufgabenaktivität';
$string['practicedisclaimer'] = 'Übungsmodus-Haftungsausschluss';
$string['practicedisclaimer_text'] = 'Haftungsausschluss, der dem KI-Feedback im Übungsmodus angehängt wird (automatische Generierung ohne Bewertungsworkflow). Dieser Text weist darauf hin, dass das Feedback nicht von einer Lehrkraft überprüft wurde.';
$string['privacy:aipath'] = 'KI-Feedback';
$string['privacy:metadata:aitext'] = 'KI-Feedbacktext.';
$string['privacy:metadata:assignmentid'] = 'Aufgaben-ID';
$string['privacy:metadata:tablesummary'] = 'Hier wird das von KI-Anbietern generierte Feedback als Rückmeldung für Lernende zu ihrer Abgabe gespeichert.';
$string['progressstepextracting'] = 'Abgabeinhalte werden extrahiert...';
$string['progresssteppreparing'] = 'Abgabedaten werden vorbereitet...';
$string['progresssteprequesting'] = 'KI-Feedback wird angefordert...';
$string['progressstepsaving'] = 'Feedback wird gespeichert...';
$string['prompt'] = 'Prompt';
$string['prompt_help'] = 'Prompt, der an das KI-Sprachmodell (z.B. ChatGPT) gesendet wird';
$string['prompt_setting'] = 'Analysiere die Grammatik in diesem Text';
$string['prompt_text'] = 'Der Standard-Prompt, der einer neuen Instanz hinzugefügt wird';
$string['prompttemplate'] = 'Prompt-Vorlage';
$string['prompttemplate_text'] = 'Die strukturierte Vorlage für den Aufbau des KI-Prompts. Verwenden Sie Platzhalter: {{submission}}, {{rubric_section}}, {{rubric}}, {{prompt}}, {{assignmentname}}, {{description_section}}, {{instructions_section}}, {{description}}, {{activityinstructions}}, {{language}}. Abschnittsplatzhalter ({{description_section}}, {{instructions_section}}, {{rubric_section}}) enthalten den vollständigen Abschnitt mit Überschrift und sind leer, wenn kein Inhalt vorhanden ist.';
$string['purposeplacedescription_feedback'] = 'KI-Feedback zu Abgaben von Lernenden generieren';
$string['purposeplacedescription_itt'] = 'Eingereichte Dokumente und Bilder für KI-Feedback in Text konvertieren';
$string['regenerate'] = 'KI-Feedback neu generieren';
$string['regenerate_queued'] = 'Die Neugenerierung des KI-Feedbacks wurde in die Warteschlange eingereiht. Bitte warten Sie, bis die Hintergrundaufgabe abgeschlossen ist.';
$string['regenerating'] = 'Wird neu generiert...';
$string['retrygeneration'] = 'Erneut versuchen';
$string['skipreason_conversionfailed'] = 'Dateikonvertierung fehlgeschlagen';
$string['skipreason_conversionnotsupported'] = 'Dateiformat wird nicht für die Konvertierung unterstützt. Unterstützte Formate: {$a}';
$string['skipreason_imageextractionfailed'] = 'KI-Bildtextextraktion fehlgeschlagen';
$string['skipreason_pdfextractionfailed'] = 'KI-PDF-Textextraktion fehlgeschlagen';
$string['studentsubmissionainotice'] = 'Diese Aufgabe verwendet KI-gestütztes Feedback. Wenn Sie Ihre Arbeit abgeben, werden Ihr Abgabetext und hochgeladene Dateien zur Analyse an ein KI-System gesendet. Die KI generiert Feedback zu Ihrer Abgabe.';
$string['taskcleanupcache'] = 'Abgelaufenen Dateiinhalts-Cache bereinigen';
$string['taskprocessfeedback'] = 'KI-Feedback verarbeiten';
$string['useexpertmodetemplate'] = 'Expertenmodus-Vorlage';
$string['waitingforadhoctaskstart'] = 'Warten auf den Start der Feedback-Generierung...';
