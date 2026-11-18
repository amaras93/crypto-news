<?php

return [
    'categories' => [
        'bitcoin' => ['Bitcoin', 'Биткоин', 'Բիթքոյն'],
        'ethereum' => ['Ethereum', 'Ethereum', 'Ethereum'],
        'altcoins' => ['Altcoins', 'Альткоины', 'Ալթքոյններ'],
        'markets' => ['Markets', 'Рынки', 'Շուկաներ'],
        'regulation' => ['Regulation', 'Регулирование', 'Կարգավորում'],
        'blockchain' => ['Blockchain', 'Блокчейн', 'Բլոկչեյն'],
        'ai' => ['AI', 'ИИ', 'ԱԲ'],
        'gold' => ['Gold', 'Золото', 'Ոսկի'],
        'silver' => ['Silver', 'Серебро', 'Արծաթ'],
    ],
    'stories' => [
        [
            'slug' => 'bitcoin-long-view', 'category' => 'bitcoin', 'image' => 'bitcoin',
            'analysis' => true, 'pick' => true,
            'titles' => ['Bitcoin’s next chapter: a market learning to think long term', 'Биткоин: рынок учится мыслить на долгую перспективу', 'Բիթքոյնի հաջորդ գլուխը․ երկարաժամկետ մտածող շուկա'],
            'excerpts' => ['Beyond the daily price swings, a quieter conversation is taking shape around patience, custody, and the next generation of investors.', 'За ежедневными колебаниями цен формируется более спокойный разговор о терпении, хранении активов и новом поколении инвесторов.', 'Գների ամենօրյա տատանումներից անդին ձևավորվում է նոր խոսակցություն՝ համբերության, ակտիվների պահպանման և ներդրողների հաջորդ սերնդի մասին։'],
        ],
        [
            'slug' => 'ethereum-next-layer', 'category' => 'ethereum', 'image' => 'ethereum',
            'analysis' => false, 'pick' => true,
            'titles' => ['Ethereum’s next frontier is closer to everyday life', 'Следующий рубеж Ethereum — повседневная жизнь', 'Ethereum-ի հաջորդ սահմանը՝ առօրյա կյանքը'],
            'excerpts' => ['A fictional look at how simpler wallets and thoughtful infrastructure could make the network feel less like technology and more like a utility.', 'Вымышленный взгляд на то, как простые кошельки и продуманная инфраструктура могут сделать сеть повседневным инструментом.', 'Հորինված հայացք այն մասին, թե ինչպես պարզ դրամապանակներն ու մտածված ենթակառուցվածքը կարող են ցանցը դարձնել առօրյա գործիք։'],
        ],
        [
            'slug' => 'gold-quiet-role', 'category' => 'gold', 'image' => 'gold',
            'analysis' => false, 'pick' => false,
            'titles' => ['The quiet role of gold in a digital-first world', 'Тихая роль золота в цифровом мире', 'Ոսկու հանգիստ դերը թվային աշխարհում'],
            'excerpts' => ['Why an ancient store of value still has a place in a very modern financial conversation.', 'Почему древнее средство сохранения стоимости по-прежнему занимает место в современном финансовом диалоге.', 'Ինչու արժեքի պահպանման հնագույն միջոցը շարունակում է իր տեղն ունենալ ժամանակակից ֆինանսական քննարկումներում։'],
        ],
        [
            'slug' => 'ai-research-desk', 'category' => 'ai', 'image' => 'network',
            'analysis' => true, 'pick' => true,
            'titles' => ['What changes when AI joins the research desk?', 'Что меняется, когда ИИ приходит в исследовательский отдел?', 'Ի՞նչ է փոխվում, երբ ԱԲ-ն միանում է հետազոտողներին'],
            'excerpts' => ['A considered exploration of better questions, human judgment, and the limits of automated financial research.', 'Взвешенный взгляд на точные вопросы, человеческое суждение и ограничения автоматизированного финансового анализа.', 'Հավասարակշռված հայացք ճշգրիտ հարցերին, մարդկային դատողությանը և ավտոմատացված ֆինանսական հետազոտության սահմաններին։'],
        ],
        [
            'slug' => 'regulation-clarity', 'category' => 'regulation', 'image' => 'markets',
            'analysis' => false, 'pick' => false,
            'titles' => ['Clearer rules, longer horizons: imagining a new market framework', 'Ясные правила и долгий горизонт: новая рыночная модель', 'Հստակ կանոններ և երկար հորիզոններ․ նոր շուկայական մոդել'],
            'excerpts' => ['Our fictional policy desk considers what transparent standards could mean for builders and readers alike.', 'Наш вымышленный отдел политики рассматривает значение прозрачных стандартов для разработчиков и читателей.', 'Մեր հորինված քաղաքականության բաժինը դիտարկում է թափանցիկ չափանիշների նշանակությունը ստեղծողների և ընթերցողների համար։'],
        ],
        [
            'slug' => 'silver-industrial-story', 'category' => 'silver', 'image' => 'silver',
            'analysis' => false, 'pick' => true,
            'titles' => ['Silver’s industrial story deserves a closer look', 'Промышленная роль серебра заслуживает внимания', 'Արծաթի արդյունաբերական դերն արժե ավելի ուշադիր դիտարկել'],
            'excerpts' => ['From energy systems to everyday electronics, a metal with more than one identity.', 'От энергетических систем до повседневной электроники — металл с многогранным характером.', 'Էներգետիկ համակարգերից մինչև առօրյա էլեկտրոնիկա՝ բազմակողմանի դեր ունեցող մետաղ։'],
        ],
        [
            'slug' => 'altcoins-useful-networks', 'category' => 'altcoins', 'image' => 'network',
            'analysis' => false, 'pick' => false,
            'titles' => ['Beyond the token: looking for useful networks', 'За пределами токена: в поисках полезных сетей', 'Թոքենից անդին․ օգտակար ցանցերի որոնում'],
            'excerpts' => ['A practical lens for separating compelling stories from sustainable digital infrastructure.', 'Практичный подход к различению ярких историй и устойчивой цифровой инфраструктуры.', 'Գործնական մոտեցում՝ գրավիչ պատմություններն ու կայուն թվային ենթակառուցվածքը տարբերակելու համար։'],
        ],
        [
            'slug' => 'blockchain-settlement', 'category' => 'blockchain', 'image' => 'ethereum',
            'analysis' => false, 'pick' => false,
            'titles' => ['A simpler settlement layer, one careful step at a time', 'К простым расчётам — шаг за шагом', 'Դեպի պարզ հաշվարկային շերտ՝ քայլ առ քայլ'],
            'excerpts' => ['A fictional case study in making complex financial processes easier to understand.', 'Вымышленный пример того, как сделать сложные финансовые процессы понятнее.', 'Հորինված օրինակ, թե ինչպես բարդ ֆինանսական գործընթացները դարձնել ավելի հասկանալի։'],
        ],
        [
            'slug' => 'markets-patience', 'category' => 'markets', 'image' => 'markets',
            'analysis' => false, 'pick' => false,
            'titles' => ['The market skill no chart can teach: patience', 'Навык, которому не научит график: терпение', 'Հմտություն, որին գծապատկերը չի սովորեցնում․ համբերություն'],
            'excerpts' => ['Perspective on noise, attention, and why a longer time frame can change the questions we ask.', 'О шуме, внимании и о том, как долгий горизонт меняет наши вопросы.', 'Աղմուկի, ուշադրության և այն մասին, թե ինչպես երկար ժամանակահատվածը կարող է փոխել մեր հարցերը։'],
        ],
        [
            'slug' => 'bitcoin-custody', 'category' => 'bitcoin', 'image' => 'bitcoin',
            'analysis' => true, 'pick' => true,
            'titles' => ['The custody question at the heart of digital ownership', 'Вопрос хранения в центре цифрового владения', 'Պահպանման հարցը՝ թվային սեփականության հիմքում'],
            'excerpts' => ['Understanding the choices behind holding digital assets, without reducing them to a single answer.', 'Разбираемся в подходах к хранению цифровых активов, не сводя всё к единственному ответу.', 'Հասկանալ թվային ակտիվների պահպանման տարբերակները՝ դրանք մեկ պատասխանի չհանգեցնելով։'],
        ],
        [
            'slug' => 'ethereum-builders', 'category' => 'ethereum', 'image' => 'ethereum',
            'analysis' => false, 'pick' => false,
            'titles' => ['Small tools, big possibilities: a builder’s perspective', 'Малые инструменты, большие возможности: взгляд разработчика', 'Փոքր գործիքներ, մեծ հնարավորություններ․ ստեղծողի հայացք'],
            'excerpts' => ['Exploring the everyday details that make decentralized applications easier to use.', 'О повседневных деталях, которые делают децентрализованные приложения удобнее.', 'Առօրյա մանրամասների մասին, որոնք ապակենտրոնացված հավելվածները դարձնում են ավելի հարմար։'],
        ],
        [
            'slug' => 'gold-silver-context', 'category' => 'gold', 'image' => 'gold',
            'analysis' => false, 'pick' => false,
            'titles' => ['Gold and silver: two metals, two different conversations', 'Золото и серебро: два металла, два разных разговора', 'Ոսկի և արծաթ․ երկու մետաղ, երկու տարբեր խոսակցություն'],
            'excerpts' => ['A fictional editorial primer on the different forces behind precious metals.', 'Вымышленный редакционный обзор различных факторов, влияющих на драгоценные металлы.', 'Հորինված խմբագրական ակնարկ թանկարժեք մետաղների վրա ազդող տարբեր գործոնների մասին։'],
        ],
        [
            'slug' => 'ai-better-questions', 'category' => 'ai', 'image' => 'network',
            'analysis' => true, 'pick' => false,
            'titles' => ['Better questions before faster answers', 'Сначала точные вопросы, потом быстрые ответы', 'Նախ՝ ավելի լավ հարցեր, ապա՝ արագ պատասխաններ'],
            'excerpts' => ['Why the next chapter of financial research may depend as much on curiosity as computing power.', 'Почему будущее финансовых исследований зависит не только от вычислительной мощности, но и от любознательности.', 'Ինչու ֆինանսական հետազոտության ապագան կարող է կախված լինել ոչ միայն հաշվարկային հզորությունից, այլև հետաքրքրասիրությունից։'],
        ],
        [
            'slug' => 'regulation-common-language', 'category' => 'regulation', 'image' => 'markets',
            'analysis' => false, 'pick' => false,
            'titles' => ['Finding a common language for digital finance', 'Общий язык для цифровых финансов', 'Ընդհանուր լեզու՝ թվային ֆինանսների համար'],
            'excerpts' => ['A fictional roundtable on vocabulary, transparency, and the value of being understood.', 'Вымышленный круглый стол о терминологии, прозрачности и ценности понимания.', 'Հորինված քննարկում բառապաշարի, թափանցիկության և հասկանալի լինելու արժեքի մասին։'],
        ],
        [
            'slug' => 'silver-new-perspective', 'category' => 'silver', 'image' => 'silver',
            'analysis' => false, 'pick' => false,
            'titles' => ['Seeing silver through a different lens', 'Новый взгляд на серебро', 'Նոր հայացք արծաթին'],
            'excerpts' => ['An editorial journey through material science, design, and a changing financial landscape.', 'Редакционное путешествие по материаловедению, дизайну и меняющемуся финансовому миру.', 'Խմբագրական ճանապարհորդություն նյութագիտության, դիզայնի և փոփոխվող ֆինանսական աշխարհի միջով։'],
        ],
    ],
];
