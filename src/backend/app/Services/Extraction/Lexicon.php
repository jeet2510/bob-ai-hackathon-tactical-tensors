<?php

namespace App\Services\Extraction;

/**
 * Surface forms → INTERPOL codebook values, across the three languages the
 * incident was recorded in.
 *
 * Families were interviewed in Marathi and Hindi; examiners wrote in clinical
 * English. Both describe the same body, so both must reduce to the same
 * vocabulary before anything can be compared. That reduction is this file.
 *
 * Marathi and Hindi appear both in Devanagari and transliterated into Latin
 * script and mixed with English ("black blouse ani purple saree ghatle hote"),
 * so surface forms are listed in whatever script they actually occur in.
 *
 * Longer phrases must be tried before shorter ones that are substrings of them
 * ("salwar top" before "salwar", "t-shirt" before "shirt"); the ordering of
 * each map is significant and is preserved by the matcher.
 */
class Lexicon
{
    /**
     * Anatomical region → the coarser body region it belongs to.
     *
     * @var array<string, string>
     */
    public const BODY_REGION = [
        'shin' => 'lower_limb',
        'thigh' => 'lower_limb',
        'knee' => 'lower_limb',
        'foot' => 'lower_limb',
        'forearm' => 'upper_limb',
        'upper_arm' => 'upper_limb',
        'shoulder' => 'upper_limb',
        'hand' => 'upper_limb',
        'wrist' => 'upper_limb',
        'back' => 'trunk',
        'chest' => 'trunk',
        'abdomen' => 'trunk',
        'cheek' => 'face',
        'chin' => 'face',
        'forehead' => 'face',
        'neck' => 'neck',
        'trunk_and_thighs' => 'trunk',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const REGION = [
        // Multi-word English clinical terms first.
        'trunk_and_thighs' => ['trunk and thighs', 'trunk and thigh'],
        'upper_arm' => ['upper arm', 'upper limb', 'दंड', 'दंडा', 'बाजू', 'dand'],
        'forearm' => ['forearm', 'बाँह', 'बांह', 'बाहु', 'हाताच्या', 'baanh'],
        'shin' => ['shin', 'lower leg', 'नडगी', 'नडगीव', 'पिंडली', 'nadgi', 'pindli'],
        'cheek' => ['malar region', 'malar', 'cheek', 'गाल', 'गालावर', 'gaal'],
        'forehead' => ['forehead', 'कपाळ', 'कपाळाव', 'माथे', 'माथा', 'kapal'],
        'shoulder' => ['shoulder', 'खांद', 'कंधे', 'कंधा', 'khanda'],
        'wrist' => ['wrist', 'मनगट', 'कलाई', 'mangat'],
        'knee' => ['knee', 'गुडघ', 'घुटन', 'घुटना', 'gudgha'],
        'thigh' => ['thigh', 'मांडी', 'मांडीव', 'जांघ', 'mandi'],
        'abdomen' => ['abdomen', 'stomach', 'belly', 'पोट', 'पेट', 'pot'],
        'chest' => ['pectoral region', 'pectoral', 'chest', 'छाती', 'chhati'],
        'back' => ['lumbar spine', 'lumbar', 'back', 'पाठ', 'पाठीव', 'पीठ', 'path'],
        'chin' => ['chin', 'हनुवटी', 'ठुड्डी', 'hanuvati'],
        'neck' => ['neck', 'मान', 'गर्दन', 'गळ्या', 'maan'],
        'hand' => ['dorsum of hand', 'dorsum of the hand', 'hand', 'हात', 'हाताव', 'haat'],
        'foot' => ['foot', 'पाय', 'पायाव', 'पैर', 'pay'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const LATERALITY = [
        'left' => ['left', 'डाव्या', 'डावा', 'डावी', 'बाएँ', 'बायाँ', 'बाये', 'davya', 'dava'],
        'right' => ['right', 'उजव्या', 'उजवा', 'उजवी', 'दाएँ', 'दायाँ', 'दाये', 'ujavya', 'ujva'],
    ];

    /**
     * Distinguishing-mark types. Specific causes before the generic scar —
     * a burn scar and a surgical scar are different evidence.
     *
     * @var array<string, list<string>>
     */
    public const MARK_TYPE = [
        'birthmark' => ['birthmark', 'birth mark', 'जन्मखूण', 'जन्मचिह्न', 'janmakhun'],
        'burn_scar' => ['burn mark', 'burn scar', 'burn', 'भाजल्याची', 'भाजल', 'जलने', 'जले', 'bhajalya'],
        'surgical_scar' => [
            'surgical scar', 'surgery scar', 'post-surgical', 'operation mark', 'operation scar',
            'operation', 'surgery', 'शस्त्रक्रिये', 'ऑपरेशन', 'shastrakriya',
        ],
        'mole' => ['pigmented naevus', 'pigmented nevus', 'naevus', 'nevus', 'mole', 'तिळ', 'तीळ', 'तिल', 'til'],
        // Generic last: anything still calling itself a scar or wound mark.
        'scar_linear' => [
            'linear scar', 'cut mark', 'cut scar', 'cut', 'scar', 'wound mark', 'wound',
            'जखमेची', 'जखम', 'चोट का निशान', 'चोट', 'निशान', 'khun', 'jakham',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const MARK_COLOUR = [
        'dark_brown' => ['dark brown', 'dark-brown'],
        'black' => ['black', 'काळ', 'काले', 'काला', 'kala'],
        'brown' => ['brown', 'तपकिरी', 'भूर', 'bhura'],
    ];

    /**
     * Clothing colours. Shade qualifiers collapse to the base colour: a family
     * saying "navy blue" and an examiner writing "blue" are agreeing.
     *
     * @var array<string, list<string>>
     */
    public const CLOTHING_COLOUR = [
        'white' => ['off-white', 'off white', 'cream', 'white', 'पांढर', 'सफेद', 'pandhar'],
        'blue' => ['navy blue', 'sky blue', 'light blue', 'dark blue', 'navy', 'blue', 'निळ', 'नील', 'नीला', 'nila'],
        'green' => ['dark green', 'light green', 'olive', 'green', 'हिरव', 'हरा', 'hirva'],
        'grey' => ['dark grey', 'light grey', 'grey', 'gray', 'करड', 'स्लेटी', 'karda'],
        'brown' => ['dark brown', 'light brown', 'brown', 'तपकिरी', 'भूर', 'bhura'],
        'black' => ['black', 'काळ', 'काले', 'काला', 'kala'],
        'red' => ['maroon', 'red', 'लाल', 'lal'],
        'purple' => ['violet', 'purple', 'जांभळ', 'बैंगनी', 'jambhal'],
        'pink' => ['pink', 'गुलाबी', 'gulabi'],
        'yellow' => ['mustard', 'yellow', 'पिवळ', 'पीला', 'pivla'],
        'orange' => ['saffron', 'orange', 'नारंगी', 'केशरी', 'narangi'],
    ];

    /**
     * Garment → the slot it occupies. One garment per slot per person, which
     * is what makes slot a useful comparison key even when the garment words
     * differ.
     *
     * @var array<string, string>
     */
    public const GARMENT_SLOT = [
        'salwar top' => 'upper',
        't-shirt' => 'upper',
        'blouse' => 'upper',
        'kurta' => 'upper',
        'hoodie' => 'upper',
        'shirt' => 'upper',

        'track pants' => 'lower',
        'saree' => 'lower',
        'salwar' => 'lower',
        'jeans' => 'lower',
        'trousers' => 'lower',
        'leggings' => 'lower',
        'shorts' => 'lower',
        'lungi' => 'lower',

        'rubber boots' => 'footwear',
        'leather shoes' => 'footwear',
        'sports shoes' => 'footwear',
        'chappals' => 'footwear',
        'sandals' => 'footwear',
    ];

    /**
     * Ordered longest-first so "salwar top" is not read as "salwar", and
     * "t-shirt" not as "shirt".
     *
     * @var array<string, list<string>>
     */
    public const GARMENT = [
        'salwar top' => ['salwar top', 'salwar kameez', 'kameez'],
        'track pants' => ['track pants', 'trackpants', 'track pant'],
        'rubber boots' => ['rubber boots', 'gumboots', 'rubber boot'],
        'leather shoes' => ['leather shoes', 'leather shoe'],
        'sports shoes' => ['sports shoes', 'sport shoes', 'sneakers', 'canvas shoes'],
        't-shirt' => ['t-shirt', 'tshirt', 't shirt', 'tee shirt'],
        'chappals' => ['chappals', 'chappal', 'slippers', 'slipper'],
        'sandals' => ['sandals', 'sandal'],
        'saree' => ['saree', 'sari', 'साडी'],
        'blouse' => ['blouse', 'चोळी'],
        'salwar' => ['salwar', 'shalwar'],
        'leggings' => ['leggings', 'legging'],
        'trousers' => ['trousers', 'trouser', 'pants'],
        'jeans' => ['jeans', 'denim'],
        'shorts' => ['shorts'],
        'hoodie' => ['hoodie', 'hooded sweatshirt'],
        'lungi' => ['lungi', 'dhoti'],
        'kurta' => ['kurta', 'kurtha', 'कुर्ता'],
        'shirt' => ['shirt'],
    ];

    /**
     * @var array<string, array{site: string, forms: list<string>}>
     */
    public const JEWELLERY = [
        'mangalsutra' => ['site' => 'neck', 'forms' => ['mangalsutra', 'मंगळसूत्र', 'मंगलसूत्र']],
        'toe_ring' => ['site' => 'foot', 'forms' => ['toe ring', 'toe-ring', 'जोडवी', 'बिछिया']],
        'nose_stud' => ['site' => 'nose', 'forms' => ['nose stud', 'nose-stud', 'nose pin', 'नथ']],
        'earrings' => ['site' => 'ear', 'forms' => ['earrings', 'earring', 'ear studs', 'कानातल', 'बाली']],
        'bangles' => ['site' => 'wrist', 'forms' => ['bangles', 'bangle', 'बांगड्या', 'चूड़ियाँ']],
        'kada' => ['site' => 'wrist', 'forms' => ['kada', 'kadaa', 'कडं', 'कड़ा']],
        'watch' => ['site' => 'wrist', 'forms' => ['wristwatch', 'watch', 'घड्याळ', 'घड़ी']],
        'thread' => ['site' => 'wrist', 'forms' => ['sacred thread', 'thread', 'दोरा', 'धागा']],
        'chain' => ['site' => 'neck', 'forms' => ['necklace', 'chain', 'साखळी', 'चेन']],
        'ring' => ['site' => 'hand', 'forms' => ['ring', 'अंगठी', 'अंगूठी']],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const METAL = [
        'yellow_metal' => ['gold', 'golden', 'yellow metal', 'सोन', 'सोने', 'सोना'],
        'white_metal' => ['silver', 'white metal', 'चांदी', 'चांदीच'],
        'other' => ['metal', 'steel', 'plastic', 'leather'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const BELONGING = [
        'mobile_phone' => ['mobile phone', 'mobile', 'cellphone', 'cell phone', 'phone', 'मोबाइल', 'मोबाईल'],
        'earphones' => ['earphones', 'earphone', 'headphones', 'earbuds'],
        'spectacles' => ['spectacles', 'eyeglasses', 'glasses', 'चष्मा', 'चश्मा'],
        'backpack' => ['backpack', 'rucksack', 'school bag', 'सॅक'],
        'handbag' => ['handbag', 'purse', 'पर्स'],
        'umbrella' => ['umbrella', 'छत्री', 'छाता'],
        'wallet' => ['wallet', 'पाकीट', 'बटुआ'],
        'keys' => ['keys', 'key', 'चाव्या', 'चाबी'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const ID_KIND = [
        'voter-style' => ['voter-style', 'voter style', 'voter'],
        'office' => ['office'],
        'school' => ['school'],
        'college' => ['college'],
    ];

    /**
     * Surgical implants. A device serial number recovered from both sides is
     * among the strongest secondary identifiers available.
     *
     * @var array<string, array{region: ?string, forms: list<string>}>
     */
    public const IMPLANT = [
        'knee_replacement' => [
            'region' => 'knee',
            'forms' => ['knee arthroplasty', 'knee replacement', 'knee prosthesis', 'total knee'],
        ],
        'spine_screws' => [
            'region' => 'back',
            'forms' => ['pedicle screws', 'spinal screws', 'spine screws'],
        ],
        'pacemaker' => [
            'region' => 'chest',
            'forms' => ['pacemaker'],
        ],
        'rod_femur' => [
            'region' => 'thigh',
            'forms' => ['intramedullary rod', 'femoral rod', 'femur rod', 'nail in the femur'],
        ],
        'plate_forearm' => [
            'region' => 'forearm',
            'forms' => ['plate and screws', 'metal plate', 'plate', 'orif'],
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TATTOO_DESIGN = [
        'dotted_traditional' => ['dotted traditional', 'traditional dotted', 'godna', 'गोंदण', 'गुदना'],
        'tribal_band' => ['tribal band', 'tribal armband', 'tribal'],
        'initials_sp' => ['initials s.p.', 'initials sp', 's.p.', 'एस.पी.'],
        'trishul' => ['trishul', 'trident', 'त्रिशूळ', 'त्रिशूल'],
        'lotus' => ['lotus', 'कमळ', 'कमल'],
        'aai_text' => ['aai', 'आई', 'maa', 'माँ'],
        'om' => ['om symbol', 'om', 'ॐ', 'ओम'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const HAIR_COLOUR = [
        // White hair is recorded as grey in the codebook.
        'grey' => ['grey', 'gray', 'white', 'greying', 'salt and pepper', 'पांढर', 'सफेद', 'pandhar'],
        'brown' => ['brown', 'तपकिरी', 'भूर'],
        'black' => ['black', 'काळ', 'काले', 'काला', 'kala'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const HAIR_LENGTH = [
        'medium' => ['medium', 'मध्यम', 'madhyam'],
        'short' => ['short', 'लहान', 'छोटे', 'छोटा', 'lahan'],
        'long' => ['long', 'लांब', 'लंबे', 'लंबा', 'lamb'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const EYE_COLOUR = [
        'dark_brown' => ['dark brown', 'dark-brown'],
        'brown' => ['brown', 'तपकिरी', 'भूर', 'भूरी'],
        'black' => ['black', 'काळ', 'काले', 'काला'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const SKIN_TONE = [
        'medium' => ['medium brown', 'medium', 'wheatish', 'wheat', 'गव्हाळ', 'गेहुँआ', 'गेहुआ'],
        'dark' => ['dark brown', 'dark', 'सावळ', 'साँवल', 'सांवल'],
        'fair' => ['fair', 'light', 'गोर', 'गोरा'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const BUILD = [
        'slight' => ['slight', 'thin', 'slim', 'lean', 'बारीक', 'पातळ', 'दुबल', 'patla'],
        'large' => ['large', 'heavy', 'stout', 'obese', 'जाड', 'भारी', 'mota'],
        'medium' => ['medium', 'average', 'मध्यम', 'madhyam'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const FACIAL_HAIR = [
        'clean_shaven' => ['clean shaven', 'clean-shaven', 'cleanshaven', 'दाढी नाही', 'सफाचट'],
        'moustache' => ['moustache', 'mustache', 'मिशी', 'मूंछ'],
        'beard' => ['beard', 'दाढी', 'दाढ़ी'],
    ];

    /**
     * Phrases meaning the examiner could not assess a feature at all.
     *
     * This is the single most important distinction in the whole lexicon: a
     * burnt trunk or slipped skin means *no evidence*, which is not the same
     * as evidence of absence, and must never be scored as a conflict.
     *
     * @var list<string>
     */
    public const NOT_ASSESSABLE = [
        'not assessable',
        'cannot be assessed',
        'could not be assessed',
        'unassessable',
        'not examinable',
        'obscured',
        'skin slippage',
        'charred',
        'not evaluable',
    ];

    /**
     * Phrases asserting a feature is genuinely absent. "No tattoos" is real
     * evidence; it is not the same as being unable to look.
     *
     * @var list<string>
     */
    public const NEGATION = [
        'no tattoos noted',
        'no tattoo',
        'no tattoos',
        'none noted',
        'no distinguishing marks',
        'no marks',
        'none',
        'nil',
        'गोंदण नाही',
        'टॅटू नाही',
        'कोणतीही खूण नाही',
        'कोई टैटू नहीं',
        'कोई निशान नहीं',
    ];
}
