# How to use Envision

Envision is the media unit's desk: the sermon archive behind the public Sermons page, the media crew and who served at each service, the comments people leave on sermons, and the live audio broadcast.

## Sermons

- **Archive sermon** adds one: title, preacher, date and service, then the media. Everything except the title, preacher and date is optional and can be added later with **Edit**.
- **YouTube**: paste the video's link in any form (watch, youtu.be, shorts or embed). It is stored as an embed link.
- **Cover**: a JPG, PNG or WebP image. **Audio**: MP3, WAV or M4A. Each file is stored under a new name; editing a sermon without choosing a new file keeps the one already there.
- **Live / Hidden** decides whether the sermon shows on the public Sermons page. It needs Approve in Roles & permissions.
- **Crew** opens the service duties: who filmed, took photos, ran sound, projection, lighting or notes for that service. Each person is told about the duty.

## Crew

The media crew is the Envision team placed in a specialisation. **Add to crew** lists the people seated in the Envision department who are not on the crew yet; the pencil on a card changes someone's specialisation. Running the crew needs Manage.

## Comments

Every comment left on the public Sermons page, newest first, 50 at a time. Search by a word or a name; **Delete** removes a comment for good (it needs Delete).

## Live radio: setting up the broadcast

You need a stream provider that gives you a public listen link (Zeno.fm or Caster.fm both work on a free plan) and OBS Studio on the media desk computer.

1. **Create the station.** Sign up with the provider, open its control panel and start the server.
2. **Point OBS at it.** In the provider's panel find the broadcast settings (server, port, mount and password). In OBS open *Settings → Stream*, choose *Custom…*, and enter the server as `icecast://SERVER:PORT` and the stream key as `source:PASSWORD/MOUNT`.
3. **Set the audio bitrate.** In OBS *Settings → Output*, switch to *Advanced* and set the audio bitrate to **96**. Free plans cap the bitrate; matching it keeps the stream stable.
4. **Save the listen link here.** Copy the provider's public listen link (it usually ends in `/listen.mp3` or similar) into **Stream link** on the Live radio tab and press **Save link**.

## Live radio: on Sunday

1. In OBS press **Start Streaming** and wait about ten seconds.
2. On the Live radio tab press **Test link**. It checks that audio is reaching the provider.
3. Switch **Broadcast** on. The live player appears for the congregation straight away.
4. When the service ends, press **Stop Streaming** in OBS and switch **Broadcast** off.

The stream settings and the switch need Manage in Roles & permissions.
