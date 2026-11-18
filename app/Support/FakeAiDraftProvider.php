<?php

namespace App\Support;

use App\AiRunStatus;
use App\Models\AiGenerationRun;

class FakeAiDraftProvider implements AiDraftProvider
{
    public function generateDraft(array $input): array
    {
        $run = AiGenerationRun::findOrFail($input['run_id']);
        if (! app()->environment(['local', 'testing']) || ! $run->is_simulated || $run->provider !== 'fake'
            || $run->status !== AiRunStatus::Requesting) {
            throw new AiFailure('simulation_not_permitted');
        }

        return ['document' => $this->fixture($run), 'usage' => ['input_tokens' => 800, 'cached_input_tokens' => 0,
            'cache_write_tokens' => 0, 'output_tokens' => 400], 'response_id' => null];
    }

    /** Deterministic fictional documents; never used as a paid-provider fallback. */
    public function fixture(AiGenerationRun $run): array
    {
        $copy = [
            'en' => ['Offline demonstration: Sandbox Exchange documentation',
                'Sandbox Exchange (SBX) provides fictional documentation for an offline newsroom exercise. This is simulated material, not market reporting.',
                'The fictional Sandbox Exchange (SBX) has released documentation for a newsroom training exercise. This offline preview demonstrates how editors inspect attributed claims before approving a draft.',
                'The exercise uses invented source material and has no connection to real trading activity. Sandbox Exchange (SBX) is a test organization; this document must remain a private demonstration.',
                ['This is fictional offline training material.', 'Sandbox Exchange (SBX) is a simulated organization.', 'Human review is required before further editorial work.'], 'The demonstration', 'Editorial context'],
            'ru' => ['Учебный пример: документация Sandbox Exchange',
                'Sandbox Exchange (SBX) предоставляет вымышленные документы для автономного редакционного упражнения. Это учебный материал, а не рыночные новости.',
                'Вымышленная Sandbox Exchange (SBX) подготовила документацию для обучения редакторов. Автономный просмотр показывает, как редактор проверяет утверждения и ссылки перед одобрением текста.',
                'В упражнении используются вымышленные источники без связи с реальными торгами. Sandbox Exchange (SBX) является тестовой организацией; материал предназначен только для закрытой демонстрации.',
                ['Это вымышленный учебный материал.', 'Sandbox Exchange (SBX) — тестовая организация.', 'Для дальнейшей работы требуется проверка редактора.'], 'Учебный пример', 'Редакционный контекст'],
            'fr' => ['Démonstration hors ligne : documentation Sandbox Exchange',
                'Sandbox Exchange (SBX) fournit des documents fictifs pour un exercice rédactionnel hors ligne. Ce contenu simulé ne constitue pas une actualité financière.',
                'La société fictive Sandbox Exchange (SBX) propose une documentation destinée à la formation des rédacteurs. Cet aperçu hors ligne montre comment vérifier les affirmations attribuées avant de valider un brouillon.',
                'Cet exercice repose sur des sources inventées et ne concerne aucune activité réelle de négociation. Sandbox Exchange (SBX) est une organisation de test ; le document reste une démonstration privée.',
                ['Ce contenu est un exercice fictif.', 'Sandbox Exchange (SBX) est une organisation simulée.', 'Une vérification humaine reste nécessaire.'], 'La démonstration', 'Contexte rédactionnel'],
            'de' => ['Offline-Demonstration: Dokumentation von Sandbox Exchange',
                'Sandbox Exchange (SBX) stellt fiktive Dokumente für eine redaktionelle Offline-Übung bereit. Der simulierte Inhalt ist keine Finanznachricht.',
                'Die fiktive Sandbox Exchange (SBX) hat eine Dokumentation für die Ausbildung von Redakteuren erstellt. Die Offline-Vorschau zeigt die Prüfung belegter Aussagen vor der Freigabe eines Entwurfs.',
                'Die Übung verwendet erfundene Quellen und bezieht sich auf keinen tatsächlichen Handel. Sandbox Exchange (SBX) ist eine Testorganisation; das Dokument bleibt eine private Demonstration.',
                ['Dies ist fiktives Übungsmaterial.', 'Sandbox Exchange (SBX) ist eine simulierte Organisation.', 'Eine menschliche Prüfung ist weiterhin erforderlich.'], 'Die Demonstration', 'Redaktioneller Kontext'],
            'es' => ['Demostración sin conexión: documentación de Sandbox Exchange',
                'Sandbox Exchange (SBX) ofrece documentos ficticios para un ejercicio editorial sin conexión. Este contenido simulado no es información del mercado.',
                'La organización ficticia Sandbox Exchange (SBX) ha preparado documentación para formar a editores. La vista previa sin conexión muestra cómo comprobar afirmaciones atribuidas antes de aprobar un borrador.',
                'El ejercicio utiliza fuentes inventadas y no guarda relación con operaciones reales. Sandbox Exchange (SBX) es una organización de prueba; el documento debe seguir siendo una demostración privada.',
                ['Este material de formación es ficticio.', 'Sandbox Exchange (SBX) es una organización simulada.', 'La revisión humana sigue siendo obligatoria.'], 'La demostración', 'Contexto editorial'],
            'it' => ['Dimostrazione offline: documentazione di Sandbox Exchange',
                'Sandbox Exchange (SBX) offre documenti fittizi per un esercizio redazionale offline. Questo contenuto simulato non è una notizia di mercato.',
                'La fittizia Sandbox Exchange (SBX) ha preparato documentazione per la formazione dei redattori. Questa anteprima offline mostra come verificare le affermazioni attribuite prima di approvare una bozza.',
                'L’esercizio usa fonti inventate e non riguarda attività di negoziazione reali. Sandbox Exchange (SBX) è un’organizzazione di prova; il documento rimane una dimostrazione privata.',
                ['Questo è materiale didattico fittizio.', 'Sandbox Exchange (SBX) è un’organizzazione simulata.', 'La verifica umana rimane obbligatoria.'], 'La dimostrazione', 'Contesto redazionale'],
            'ja' => ['オフライン実演：Sandbox Exchange の資料',
                'Sandbox Exchange (SBX) は、編集研修用の架空の資料を提供します。これはオフラインの実演であり、実際の金融市場に関する報道ではありません。',
                '架空の組織である Sandbox Exchange (SBX) は、編集者の研修に使う資料を用意しました。このオフラインのプレビューでは、草稿を承認する前に出典付きの主張を確認する手順を示します。',
                'この演習は架空の出典を使っており、実際の取引とは関係ありません。Sandbox Exchange (SBX) はテスト用の組織であり、この文書は非公開の実演として扱う必要があります。',
                ['これは架空の研修用資料です。', 'Sandbox Exchange (SBX) は模擬組織です。', '編集作業には人による確認が必要です。'], '実演の内容', '編集上の背景'],
            'zh' => ['离线演示：Sandbox Exchange 文档',
                'Sandbox Exchange (SBX) 提供用于离线编辑培训的虚构文档。这是模拟材料，不是对真实金融市场的新闻报道，也不涉及实际交易。',
                '虚构机构 Sandbox Exchange (SBX) 准备了供编辑培训使用的文档。此离线预览展示编辑在批准草稿之前，如何核查有来源标注的事实主张及相关内容。',
                '本练习使用虚构来源，与真实交易活动无关。Sandbox Exchange (SBX) 是测试机构，本文档只能作为非公开演示，不能被当成真实市场新闻发布。',
                ['这是虚构的离线培训材料。', 'Sandbox Exchange (SBX) 是模拟机构。', '后续编辑工作必须经过人工核查。'], '演示内容', '编辑背景'],
        ][$run->locale];
        $claims = array_column($run->evidence_snapshot['claims'], 'id');
        $sections = [
            ['key' => 'story', 'heading' => $copy[5], 'paragraphs' => [['text' => $copy[2], 'claim_ids' => [$claims[0]]]]],
            ['key' => 'background', 'heading' => $copy[6], 'paragraphs' => [['text' => $copy[3], 'claim_ids' => [$claims[1]]]]],
        ];
        if ($run->task === 'section') {
            $sections = [['key' => $run->section_key, 'heading' => $copy[6], 'paragraphs' => [['text' => $copy[2], 'claim_ids' => [$claims[0]]]]]];
        }

        return ['version' => 'draft-v1', 'language' => $run->locale, 'headline' => $copy[0], 'excerpt' => $copy[1],
            'seo_title' => $copy[0], 'seo_description' => $copy[1], 'slug' => 'offline-demonstration-'.$run->candidate->id.'-'.$run->locale,
            'takeaways' => $copy[4], 'sections' => $sections, 'entities' => ['Sandbox Exchange', 'SBX'],
            'suggested_tags' => [], 'editorial_notes' => ['SIMULATED fixture; private offline preview only.'],
            'unsupported_claim_warnings' => []];
    }
}
