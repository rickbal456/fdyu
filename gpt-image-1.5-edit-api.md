# Welcome to the RunningHub API. Easily invoke RunningHub's standard model APIs.

## Start Using

### Register User

To use the Standard Model API, register as a RunningHub user and top up your wallet. Only Enterprise Shared API Keys are supported.

### Get Your API Key

RunningHub automatically generates a unique 32-bit API KEY for each user

Please keep your API KEY safe and do not disclose it. Subsequent steps will depend on this key for operations

### Submit a request

Submit an API request. RunningHub API has handled the API Key for you, you just need to submit the request

```curl
curl --location --request POST 'https://www.runninghub.ai/openapi/v2/rhart-image-g-1.5/edit' \
--header "Content-Type: application/json" \
--header "Authorization: Bearer ${RUNNINGHUB_API_KEY}" \
--data-raw '{
  "prompt": "A sudden, massive explosion erupts in the background—blazing fireball, thick black smoke, and debris flying through the air. In the foreground, three people react in sheer panic: one screams with mouth wide open, another instinctively raises an arm to shield against the blast wave, and the third stumbles backward with eyes wide in terror. Harsh orange-red light from the explosion casts dramatic highlights on their faces and silhouettes their figures. The scene is chaotic, intense, and hyper-realistic, conveying raw human fear.",
  "imageUrls": [
    "https://www.runninghub.cn/view?filename=745f35f4b7d1bb6a478f887c6793fb1e646cbcab066ee8d1b36259c6e8552d75.png&type=input&subfolder=&Rh-Comfy-Auth=eyJ1c2VySWQiOiI2YmM2OGI0OTM1OWJkYjU2YzNlYWExYjdlN2JkZGIyYyIsInNpZ25FeHBpcmUiOjE3Njg4OTU2ODgzMTQsInRzIjoxNzY4MjkwODg4MzE0LCJzaWduIjoiZmFiNzMwMDQ3YzMxYWRiNjY4YTg3MzZmYTdmZTA2NDMifQ==&Rh-Identify=6bc68b49359bdb56c3eaa1b7e7bddb2c&rand=0.5644738392840696"
  ],
  "aspectRatio": "2:3"
}'
```

#### Request Parameters

| Schema | Type | Required/Optional | The generated result from the AI application. |
| --- | --- | --- | --- |
| `prompt` | STRING | Required | undefined<br>Text length limit: 5 - 800 |
| `imageUrls` | IMAGE | Required | undefined<br>Max 2 images, 10 MB each |
| `aspectRatio` | LIST | Required | undefined<br>Enum values: [auto, 1:1, 3:2, 2:3] |

#### Response Example

```json
{
  "taskId": "2013508786110730241",
  "status": "RUNNING",
  "errorCode": "",
  "errorMessage": "",
  "results": null,
  "clientId": "f828b9af25161bc066ef152db7b29ccc",
  "promptTips": "{\"result\": true, \"error\": null, \"outputs_to_execute\": [\"4\"], \"node_errors\": {}}"
}
```

#### Response Fields

| Schema | Type | The generated result from the AI application. |
| --- | --- | --- |
| `taskId` | String | Task ID, used to query task status later |
| `status` | String | Current task status. Common states: QUEUED, RUNNING, SUCCESS, FAILED |
| `errorCode` | String | Error code, returned only upon failure |
| `errorMessage` | String | Detailed error message |
| `results` | List | Generation results (null when submitting) |
| `clientId` | String | Client session ID, used to identify the current connection |
| `promptTips` | String (JSON) | Validation info from the ComfyUI backend, including node IDs to execute or debugging info |

### Query Result

Query the execution progress and final results by Task ID

#### Request Example

```curl
curl --location --request POST 'https://www.runninghub.ai/openapi/v2/query' \
--header "Content-Type: application/json" \
--header "Authorization: Bearer ${RUNNINGHUB_API_KEY}" \
--data-raw '{
  "taskId": "${RUNNINGHUB_TASKID}"
}'
```

#### Response Example

```json
{
  "taskId": "2013508786110730241",
  "status": "SUCCESS",
  "errorCode": "",
  "errorMessage": "",
  "results": [
    {
      "url": "https://rh-images-1252422369.cos.ap-beijing.myqcloud.com/b04e28cad0ee39193921a30a2eb4dc00/output/ComfyUI_00001_plhjr_1768892915.png",
      "outputType": "png",
      "text": null
    }
  ],
  "clientId": "",
  "promptTips": ""
}
```

#### Response Fields

| Schema | Type | The generated result from the AI application. |
| --- | --- | --- |
| `taskId` | String | Task ID |
| `status` | String | Final task status. SUCCESS indicates successful generation |
| `results` | List | List of generation results, including images, videos, or text outputs |
| ├ `url` | String | Download link for the result file (CDN URL) |
| ├ `outputType` | String | File extension (e.g., png, mp4, txt) |
| └ `text` | String | Content is displayed here if the output is plain text |
| `errorCode` | String | Error code (if any) |
| `errorMessage` | String | Error message (if any) |
