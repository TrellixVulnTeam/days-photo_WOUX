<?php

namespace App\Http\Controllers;

use App\Jobs\StoreImageJob;
use App\Jobs\StoreLineImageMessageToS3Job;
use App\Models\ImageFromUser;
use App\Models\ImageSet;
use App\Models\LineUser;
use App\Models\Album;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\FacadesLog;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;
use LINE\LINEBot\TemplateActionBuilder\UriTemplateActionBuilder;
use LINE\LINEBot\MessageBuilder\RawMessageBuilder;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Session;
use Throwable;

class LineEventController extends Controller
{
    public function process(Request $request)
    {
        foreach ($request->events as $event) {
            $event = json_decode(json_encode($event), false);

            // TODO: delete (This is just for developing)
            if (config('app.env') !== 'production') {
                \Log::info(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            switch ($event->type) {
                case 'postback':
                    $this->verifySignature($request) || abort(400);
                    $this->postbacked($event);
                    break;
                case 'follow':
                    $this->followed($event);
                    break;
                case 'unfollow':
                    $this->unfollowed($event);
                    break;
                case 'accountLink':
                    $this->verifySignature($request) || abort(400);
                    $this->accountLinked($event);
                    break;
                case 'join': // getting event when invited to group
                    $this->joined($event);
                    break;
                case 'message':
                    switch ($event->message->type) {
                        case 'image':
                            $this->verifySignature($request);
                            $isFromUser = $event->source->type === 'user';
                            if ($isFromUser && $this->isRegisted($event->source->userId)) {
                                $this->postedImageFromUser($event);
                            }
                            break;
                    }
                    break;
            }

            if (config('app.env') !== 'production' && isset($resp)) {
                \Log::info(json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        return response()->json('ok', 200);
    }

    public function postbacked($event)
    {
        parse_str($event->postback->data, $dataArr);
        $data = (object)$dataArr;
        switch ($data->action) {
            case 'save':
            case 'temporary-save':
                $this->postbackedSave($event, $data->id, $data->action);
                break;
            case 'cancel':
                $album = Album::destroy($data->id);
                ImageFromUser::where('album_id', $data->id)->delete();
                $message = "✅ 保存前のアルバムが削除されました。";
                $bot = $this->initBot();
                $bot->replyText($event->replyToken, $message);
                break;
            case 'add':
                $message = "追加したい画像を送信してください✨";
                $bot = $this->initBot();
                $bot->replyText($event->replyToken, $message);
                break;
        }
    }

    /**
     * TODO: 以下を選べるようにする
     * - サイトでみる
     * - 名前を変える
     * - デザインタイプ変更
     * - 注文する、印刷する
     * - 今はなにもしない
     */
    public function postbackedSave($event, $albumId, $type)
    {
        $replyToken = $event->replyToken;
        $dateStr = Carbon::today()->format('Y年n月j日');
        $title = "{$dateStr}に作成";
        switch ($type) {
            case 'save':
                $deleteDate = null;
                $message = "✅ アルバム『{$title}』が保存されました。";
                break;
            case 'temporary-save':
                $daysForStore = 14;
                $deleteDate = Carbon::today()->addDays($daysForStore);
                $message = "✅ アルバム『{$title}』が一時保存されました。\n\n保存期間は、{$daysForStore}日間です。";
                break;
        }

        // update Album 
        $album = Album::find($albumId);
        $album->status = 'uploading';
        $album->title = $title;
        $album->date_to_delete = $deleteDate;
        $album->cover = ImageFromUser::where('album_id', $albumId)->first()->id;
        $album->save();

        // dispatch store image jobs
        $jobs = [];
        $imageFromUsers = ImageFromUser::where('album_id', $albumId)->get();
        foreach ($imageFromUsers as $imageFromUser) {
            $jobs[] = new StoreImageJob($imageFromUser->id, $imageFromUser->message_id);
        }
        $batch = Bus::batch($jobs)
            ->then(function (Batch $batch) use ($albumId) {
                $album = Album::find($albumId);
                $album->status = 'uploaded';
                $album->save();
            })->catch(function (Batch $batch, Throwable $e) {
                Log::error($e->getMessage());
            })->finally(function (Batch $batch) use ($replyToken, $message) {
                $httpClient = new CurlHTTPClient(config('services.line.messaging_api.access_token'));
                $bot = new LINEBot($httpClient, ['channelSecret' => config('services.line.messaging_api.channel_secret')]);
                $bot->replyText($replyToken, $message);
            })->dispatch();
    }

    public function accountLinked($event)
    {
        if ($event->link->result === 'ok') {
            $bot = $this->initBot();
            $multiMessage = new MultiMessageBuilder();
            $text = "アカウント登録が完了しました 🎉\n\n『days.』は、30秒でアルバムが作れる ”かんたんフォト管理” サービス。\n\n✅ 機能①\nこのアカウントにまとめて画像を送信すると、”ずっと残るアルバム”が作成されます✨\n\n✅ 機能②\n保存されているアルバムは、ワンクリックで部屋にかざれるミニフォトブックとして発送可✨\n\nほかにも様々な便利機能を準備中です（現在β版）";
            $multiMessage->add(new TextMessageBuilder($text));
            $bot->replyMessage($event->replyToken, $multiMessage);
        }
    }

    public function isRegisted($userId)
    {
        return LineUser::where('id', $userId)->exists();
    }

    public function postedImageFromUser($event)
    {
        // 作成途中のAlbumを取得、なければ作成
        $album = Album::firstOrCreate(
            [
                'line_user_id' => $event->source->userId,
                'status' => 'default',
            ],
            [
                'id' => (string) \Str::uuid(),
                'message_id' => $event->message->id,
            ]
        );

        if (isset($event->message->imageSet)) {
            /**
             * ImageSetの序列管理
             * 
             * 画像がImageSetとして複数同時投稿される場合、Event受信が順不同になりうる問題
             * Sessionとは、UserごとにCookieと併用する一時データ管理方法であり、LINE WebHookサーバーとのやり取りでは使えない
             * そのためDatabaseを使用している
             */

            $imageSetId = $event->message->imageSet->id;
            $imageSetTotal = $event->message->imageSet->total;
            $imageSetIndex = $event->message->imageSet->index;
            $imageSet = ImageSet::firstOrCreate(['id' => $event->message->imageSet->id,]);
            $imageSet->increment('count', 1);
            $index = $album->total + $imageSetIndex;
            if ($imageSet->count === $imageSetTotal) {
                $imageSet->delete();
                $album->increment('total', $imageSetTotal);
                $this->replyForPostedImageFromUser($album->total, $album->id, $event->replyToken);
            }
        } else {
            $album->increment('total', 1);
            $index = $album->total;
            $this->replyForPostedImageFromUser($album->total, $album->id, $event->replyToken);
        }

        // 投稿された画像情報を保存
        $imageFromUser = ImageFromUser::create([
            'id' => (string) \Str::uuid(),
            'album_id' => $album->id,
            'line_user_id' => $event->source->userId,
            'message_id' => $event->message->id,
            'index' => $index,
        ]);
    }

    public function replyForPostedImageFromUser($total, $albumId, $replyToken)
    {
        $bot = $this->initBot();
        $array = [
            'type' => 'text',
            'text' => "画像を受信しました（トータル {$total}枚）",
            'quickReply' => [
                'items' => [
                    [
                        'type' => 'action',
                        'action' => [
                            'type' => 'postback',
                            'label' => '💎 ずっと残る保存',
                            'data' => "action=save&id={$albumId}",
                            'text' => "保存",
                        ]
                    ],
                    [
                        'type' => 'action',
                        'action' => [
                            'type' => 'postback',
                            'label' => '🌠 スグ消える保存',
                            'data' => "action=temporary-save&id={$albumId}",
                            'text' => "一時保存",
                        ]
                    ],
                    [
                        'type' => 'action',
                        'action' => [
                            'type' => 'postback',
                            'label' => '❌ キャンセル',
                            'data' => "action=cancel&id={$albumId}",
                            'text' => "キャンセル",
                        ]
                    ],
                    [
                        'type' => 'action',
                        'action' => [
                            'type' => 'postback',
                            'label' => '🖼️ 画像を追加',
                            'data' => "action=add&id={$albumId}",
                            'text' => "画像を追加",
                        ]
                    ],
                ]
            ]
        ];
        $rawMessage = new RawMessageBuilder($array);
        $bot->replyMessage($replyToken, $rawMessage);
    }

    public function unfollowed(Type $var = null)
    {
    }

    public function followed($event)
    {
        $bot = $this->initBot();
        $multiMessage = new MultiMessageBuilder();
        $multiMessage->add(new TextMessageBuilder("こんにちは。\n\n新しいタイプの “かんたんフォト管理サービス” 『days.』です。\n\nこのアカウントは、フォト管理に役立つ機能を提供します。"));
        $multiMessage = $this->addTermsMessage($multiMessage);
        $bot->replyMessage($event->replyToken, $multiMessage);
    }

    public function joined($event)
    {
        $bot = $this->initBot();
        $multiMessage = new MultiMessageBuilder();
        $multiMessage->add(new TextMessageBuilder("こんにちは。\n\n新しいタイプの 'かんたんフォト管理サービス' 『days.』です。\n\n『days.』を友だち登録すると、フォト管理に役立つ機能を提供します。\n\nただし、グループメンバーが『days.』を登録していない場合、そのメンバーのアクションには一切関与しません。\n\nサービスを利用したいときは、下のリンクから友だち登録をお願いします。"));
        $multiMessage->add(new TextMessageBuilder('https://lin.ee/O6NF5rk'));
        $bot->replyMessage($event->replyToken, $multiMessage);

        $groupSummaryJson = $bot->getGroupSummary($event->source->groupId);
        $groupSummary = json_decode($groupSummaryJson, false);
        LineGroup::updateOrCreate([
            [
                'line_group_id' => $groupSummary->groupId
            ],
            [
                'name' => $groupSummary->groupId,
                'picture_url' => $groupSummary->pictureUrl,
            ]
        ]);
    }

    public function addTermsMessage($multiMessage)
    {
        $terms_button = new UriTemplateActionBuilder('利用規約', 'https://days.photo/terms');
        $pp_button = new UriTemplateActionBuilder('プライバシーポリシー', 'https://days.photo/pp');
        $regist_button = new UriTemplateActionBuilder('ユーザー登録', 'https://days.photo/login/line');
        $actions = [
            $terms_button,
            $pp_button,
            $regist_button
        ];
        $buttonTemplage = new ButtonTemplateBuilder("以下を必ずご確認いただき、同意できる場合のみユーザー登録にお進みください。", $actions);
        $templateMessage = new TemplateMessageBuilder('テンプレートタイトル', $buttonTemplage);
        $multiMessage->add($templateMessage);
        return $multiMessage;
    }

    public function initBot(): LINEBot
    {
        $httpClient = new CurlHTTPClient(config('services.line.messaging_api.access_token'));
        return new LINEBot($httpClient, ['channelSecret' => config('services.line.messaging_api.channel_secret')]);
    }

    public function verifySignature($request)
    {
        $signatureRequested = $request->header('x-line-signature');

        if (empty($signatureRequested)) {
            return false;
        };
        $httpRequestBody = $request->getContent();
        $channelSecret = config('services.line.messaging_api.channel_secret');
        $hash = hash_hmac('sha256', $httpRequestBody, $channelSecret, true);
        $signature = base64_encode($hash);
        return $signatureRequested === $signature;
    }
}