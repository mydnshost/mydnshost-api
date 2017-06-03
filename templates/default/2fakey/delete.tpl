Hi {{ user.getRealName() }},

A 2FA Key has been removed from your account on {{ sitename }}.

    Description: {{ twofactorkey.getDescription() }}

{% include 'footer.tpl' %}
